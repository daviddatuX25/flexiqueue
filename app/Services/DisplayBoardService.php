<?php

namespace App\Services;

use App\Models\Program;
use App\Models\Session;
use App\Models\Station;
use App\Models\TransactionLog;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Per 09-UI-ROUTES §3.4: Data for client-facing informant display (no auth).
 * No internal IDs exposed (per 05-SECURITY-CONTROLS).
 *
 * TTS on the display is always played as two logical segments:
 * 1) token call segment (pre-generated token audio when available, or live TTS),
 * 2) connector + station/window phrase segment, derived from Program/Station
 *    settings here and typically spoken via on-demand server TTS.
 */
class DisplayBoardService
{
    /**
     * Get board data for the informant display: now serving, waiting by station, program name.
     */
    public function getBoardData(): array
    {
        $program = Program::query()->where('is_active', true)->first();

        if (! $program) {
            return [
                'program_name' => null,
                'date' => now()->format('F j, Y'),
                'now_serving' => [],
                'waiting_by_station' => [],
                'total_in_queue' => 0,
                'station_activity' => [],
                'staff_at_stations' => [],
                'staff_online' => 0,
                'display_scan_timeout_seconds' => 20,
                'program_is_paused' => false,
                'display_audio_muted' => false,
                'display_audio_volume' => 1.0,
                'display_tts_repeat_count' => 1,
                'display_tts_repeat_delay_ms' => 2000,
                'enable_display_hid_barcode' => true,
                'enable_display_camera_scanner' => true,
                'tts_active_language' => 'en',
                'tts_connector_phrase' => null,
                'station_tts_by_name' => [],
                'balance_mode' => null,
                'station_selection_mode' => null,
                'queueing_method_label' => null,
                'queue_mode_display' => null,
                'alternate_ratio' => null,
                'priority_first' => null,
            ];
        }

        $activeLanguage = $program->settings()->getTtsActiveLanguage();
        $connectorPhrase = $this->getConnectorPhraseForLang($program, $activeLanguage);

        $servingAndCalled = Session::query()
            ->where('program_id', $program->id)
            ->whereIn('status', ['serving', 'called'])
            ->where(fn ($q) => $q->whereNull('is_on_hold')->orWhere('is_on_hold', false))
            ->with(['currentStation', 'serviceTrack.trackSteps.process'])
            ->orderBy('started_at')
            ->get();

        $waiting = Session::query()
            ->where('program_id', $program->id)
            ->where('status', 'waiting')
            ->with(['currentStation', 'serviceTrack.trackSteps.process'])
            ->orderBy('station_queue_position')
            ->orderBy('started_at')
            ->get();

        $nowServing = $servingAndCalled->map(function (Session $s) {
            $process = $this->currentProcessForSession($s);

            return [
                'alias' => $s->alias,
                'status' => $s->status,
                'station_name' => $s->currentStation?->name ?? '—',
                'track' => $s->serviceTrack?->name ?? '—',
                'process_id' => $process ? $process['process_id'] : null,
                'process_name' => $process ? $process['process_name'] : null,
            ];
        })->values()->all();

        $byStationServing = $servingAndCalled->groupBy('current_station_id');
        $byStationWaiting = $waiting->groupBy('current_station_id');
        $stationIds = $byStationServing->keys()->merge($byStationWaiting->keys())->unique()->filter()->values();

        $waitingByStation = [];
        foreach ($stationIds as $stationId) {
            $station = $byStationServing->get($stationId)?->first()?->currentStation
                ?? $byStationWaiting->get($stationId)?->first()?->currentStation;
            $servingAtStation = $byStationServing->get($stationId) ?? collect();
            $waitingAtStation = $byStationWaiting->get($stationId) ?? collect();
            $waitingClients = $waitingAtStation->map(function (Session $s) {
                $process = $this->currentProcessForSession($s);

                return [
                    'alias' => $s->alias,
                    'process_name' => $process ? $process['process_name'] : null,
                ];
            })->values()->all();
            $waitingByStation[] = [
                'station_name' => $station?->name ?? '—',
                'aliases' => $waitingAtStation->pluck('alias')->values()->all(),
                'waiting_clients' => $waitingClients,
                'count' => $waitingAtStation->count(),
                'serving_count' => $servingAtStation->count(),
                'client_capacity' => (int) ($station?->client_capacity ?? 1),
            ];
        }

        $totalInQueue = $waiting->count() + $servingAndCalled->count();

        $stationIds = $program->stations()->pluck('id')->all();
        $stationActivity = $this->getStationActivity($stationIds, 15);

        $stationsWithStaff = $program->stations()
            ->with('assignedStaff')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $staffAtStations = $stationsWithStaff->map(fn (Station $s) => [
            'station_name' => $s->name,
            'staff' => $s->assignedStaff->map(fn (User $u) => [
                'name' => $u->name,
                'avatar_url' => $u->avatar_url,
                'availability_status' => $u->availability_status ?? 'offline',
            ])->values()->all(),
        ])->values()->all();

        // Queue/process fallbacks: only 'available' counts; offline/away = not on duty. Logout sets user to 'away'.
        $staffOnline = User::query()
            ->whereNotNull('assigned_station_id')
            ->where('is_active', true)
            ->where('availability_status', 'available')
            ->count();

        $stationTtsByName = $program->stations()
            ->get()
            ->mapWithKeys(function (Station $station) use ($activeLanguage) {
                $phrase = $this->getStationTtsPhrase($station, $activeLanguage);

                return [$station->name => $phrase];
            })
            ->all();

        $settings = $program->settings ?? [];
        $balanceMode = $program->settings()->getBalanceMode();
        $stationSelectionMode = $program->settings()->getStationSelectionMode();
        [$queueModeDisplay, $alternateRatio] = $this->buildQueueModeDisplay($program);
        $alternatePriorityFirst = array_key_exists('alternate_priority_first', $settings)
            ? (bool) $settings['alternate_priority_first']
            : (bool) ($settings['priority_first'] ?? true);

        return [
            'program_name' => $program->name,
            'date' => now()->format('F j, Y'),
            'now_serving' => $nowServing,
            'waiting_by_station' => $waitingByStation,
            'total_in_queue' => $totalInQueue,
            'station_activity' => $stationActivity,
            'staff_at_stations' => $staffAtStations,
            'staff_online' => $staffOnline,
            'display_scan_timeout_seconds' => $program->settings()->getDisplayScanTimeoutSeconds(),
            'program_is_paused' => (bool) $program->is_paused,
            'display_audio_muted' => $program->settings()->getDisplayAudioMuted(),
            'display_audio_volume' => $program->settings()->getDisplayAudioVolume(),
            'display_tts_repeat_count' => $program->settings()->getDisplayTtsRepeatCount(),
            'display_tts_repeat_delay_ms' => $program->settings()->getDisplayTtsRepeatDelayMs(),
            'enable_display_hid_barcode' => $program->settings()->getEnableDisplayHidBarcode(),
            'enable_display_camera_scanner' => $program->settings()->getEnableDisplayCameraScanner(),
            'tts_active_language' => $activeLanguage,
            'tts_connector_phrase' => $connectorPhrase,
            'station_tts_by_name' => $stationTtsByName,
            'balance_mode' => $balanceMode,
            'station_selection_mode' => $stationSelectionMode,
            'queueing_method_label' => $this->queueingMethodLabel($program),
            'queue_mode_display' => $queueModeDisplay,
            'alternate_ratio' => $alternateRatio,
            'priority_first' => $program->settings()->getPriorityFirst(),
            'alternate_priority_first' => $alternatePriorityFirst,
        ];
    }

    /**
     * Build queue mode display string and alternate ratio for display (per flexiqueue-syam).
     *
     * @return array{0: string, 1: array{0: int, 1: int}|null}
     */
    private function buildQueueModeDisplay(Program $program): array
    {
        $balanceMode = $program->settings()->getBalanceMode();

        if ($balanceMode === 'alternate') {
            [$p, $r] = $program->settings()->getAlternateRatio();

            return ['Alternate ('.$p.' : '.$r.')', [$p, $r]];
        }

        return ['FIFO', null];
    }

    /**
     * Human-readable queueing/assignment method label for display (per flexiqueue-syam).
     * Balance mode describes how the queue is served; station selection describes how station is chosen when multiple serve a process.
     */
    private function queueingMethodLabel(Program $program): string
    {
        $balanceMode = $program->settings()->getBalanceMode();
        $stationSelectionMode = $program->settings()->getStationSelectionMode();

        $balanceLabel = $balanceMode === 'alternate' ? 'Balanced (alternate)' : 'FIFO';

        $selectionLabels = [
            'fixed' => null,
            'shortest_queue' => 'Shortest queue',
            'least_busy' => 'Least busy',
            'round_robin' => 'Round robin',
            'least_recently_served' => 'Least recently served',
        ];
        $selectionLabel = $selectionLabels[$stationSelectionMode] ?? null;

        if ($selectionLabel !== null && $stationSelectionMode !== 'fixed') {
            return $balanceLabel.' · '.$selectionLabel;
        }

        return $balanceLabel;
    }

    /**
     * Get board data for a single station's display. Caller must ensure station belongs to active program.
     * Per plan: station-specific informant display (calling, queue, activity for one station).
     *
     * @return array{program_name: string|null, date: string, station_name: string, station_id: int, now_serving: array, waiting: array, station_activity: array}
     */
    public function getStationBoardData(Station $station): array
    {
        $station->loadMissing('program');
        $program = $station->program;
        $programName = $program?->name;
        $date = now()->format('F j, Y');

        $servingAndCalled = Session::query()
            ->where('program_id', $station->program_id)
            ->where('current_station_id', $station->id)
            ->whereIn('status', ['serving', 'called'])
            ->with(['serviceTrack.trackSteps.process'])
            ->orderBy('started_at')
            ->get();

        $waiting = Session::query()
            ->where('program_id', $station->program_id)
            ->where('current_station_id', $station->id)
            ->where('status', 'waiting')
            ->with(['serviceTrack.trackSteps.process'])
            ->orderBy('station_queue_position')
            ->orderBy('started_at')
            ->get();

        $nowServing = $servingAndCalled->map(function (Session $s) {
            $process = $this->currentProcessForSession($s);

            return [
                'alias' => $s->alias,
                'status' => $s->status,
                'track' => $s->serviceTrack?->name ?? '—',
                'process_name' => $process ? $process['process_name'] : null,
            ];
        })->values()->all();

        $waitingList = $waiting->map(function (Session $s, int $index) {
            $process = $this->currentProcessForSession($s);

            return [
                'alias' => $s->alias,
                'process_name' => $process ? $process['process_name'] : null,
                'position' => $index + 1,
            ];
        })->values()->all();

        $stationActivity = $this->getStationActivity([$station->id], 20);

        $activeLanguage = $program ? $program->settings()->getTtsActiveLanguage() : 'en';
        $connectorPhrase = $program ? $this->getConnectorPhraseForLang($program, $activeLanguage) : null;
        $stationPhrase = $program ? $this->getStationTtsPhrase($station, $activeLanguage) : null;

        $balanceMode = $program ? $program->settings()->getBalanceMode() : null;
        $stationSelectionMode = $program ? $program->settings()->getStationSelectionMode() : null;
        $queueingMethodLabel = $program ? $this->queueingMethodLabel($program) : null;
        [$queueModeDisplay, $alternateRatio] = $program ? $this->buildQueueModeDisplay($program) : [null, null];
        $priorityFirst = $program ? $program->settings()->getPriorityFirst() : null;
        $alternatePriorityFirst = null;
        if ($program) {
            $settings = $program->settings ?? [];
            $alternatePriorityFirst = array_key_exists('alternate_priority_first', $settings)
                ? (bool) $settings['alternate_priority_first']
                : (bool) ($settings['priority_first'] ?? true);
        }

        return [
            'program_name' => $programName,
            'date' => $date,
            'station_name' => $station->name,
            'station_id' => $station->id,
            'now_serving' => $nowServing,
            'waiting' => $waitingList,
            'station_activity' => $stationActivity,
            'display_audio_muted' => $station->getDisplayAudioMuted(),
            'display_audio_volume' => $station->getDisplayAudioVolume(),
            'tts_active_language' => $activeLanguage,
            'tts_connector_phrase' => $connectorPhrase,
            'station_tts_phrase' => $stationPhrase,
            'balance_mode' => $balanceMode,
            'station_selection_mode' => $stationSelectionMode,
            'queueing_method_label' => $queueingMethodLabel,
            'queue_mode_display' => $queueModeDisplay,
            'alternate_ratio' => $alternateRatio,
            'priority_first' => $priorityFirst,
            'alternate_priority_first' => $alternatePriorityFirst,
        ];
    }

    private function getStationTtsPhrase(Station $station, string $lang): ?string
    {
        $settings = $station->settings ?? [];
        $languages = $settings['tts']['languages'] ?? [];
        $config = $languages[$lang] ?? null;
        if (! is_array($config)) {
            return null;
        }

        $phrase = $config['station_phrase'] ?? null;

        return is_string($phrase) && trim($phrase) !== '' ? trim($phrase) : null;
    }

    /**
     * Get connector phrase for a language from program settings (for second-segment TTS generation).
     */
    public function getConnectorPhraseForLang(Program $program, string $lang): ?string
    {
        $settings = $program->settings ?? [];
        if (
            ! isset($settings['tts']['connector']['languages'][$lang])
            || ! is_array($settings['tts']['connector']['languages'][$lang])
        ) {
            return null;
        }
        $raw = $settings['tts']['connector']['languages'][$lang]['connector_phrase'] ?? null;

        return is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
    }

    /**
     * Build the second-segment TTS text for a station in a given language (connector + station phrase).
     * Matches how the display builds the phrase for playback.
     */
    public function getSecondSegmentText(Program $program, Station $station, string $lang): string
    {
        $connectorPhrase = $this->getConnectorPhraseForLang($program, $lang);
        $stationPhrase = $this->getStationTtsPhrase($station, $lang);
        $stationPhrase = $stationPhrase !== null && $stationPhrase !== ''
            ? $stationPhrase
            : $station->name;

        if ($connectorPhrase !== null && $connectorPhrase !== '') {
            return trim($connectorPhrase.' '.$stationPhrase);
        }

        return trim($stationPhrase);
    }

    /**
     * Resolve current process for a session from track step at current_step_order.
     * Per flexiqueue-ui3: show which queue/process each client is in on display.
     *
     * @return array{process_id: int, process_name: string}|null
     */
    private function currentProcessForSession(Session $s): ?array
    {
        $track = $s->serviceTrack;
        if (! $track || ! $track->relationLoaded('trackSteps')) {
            return null;
        }
        $order = (int) ($s->current_step_order ?? 1);
        $step = $track->trackSteps->firstWhere('step_order', $order);

        return $step && $step->process
            ? ['process_id' => $step->process->id, 'process_name' => $step->process->name]
            : null;
    }

    /**
     * Get last N station activities (bind, call, check_in) for display.
     * Per ISSUES-ELABORATION §10: include bind so "Recent activity" stays in sync after realtime refresh.
     *
     * @param  array<int>  $stationIds
     * @return array<int, array{station_name: string, message: string, alias: string, action_type: string, created_at: string}>
     */
    private function getStationActivity(array $stationIds, int $limit): array
    {
        if (empty($stationIds)) {
            return [];
        }

        $logs = TransactionLog::query()
            ->whereIn('action_type', ['call', 'check_in', 'bind'])
            ->where(function ($q) use ($stationIds) {
                $q->whereIn('station_id', $stationIds)
                    ->orWhere(function ($q2) use ($stationIds) {
                        $q2->where('action_type', 'bind')->whereIn('next_station_id', $stationIds);
                    });
            })
            ->with(['session', 'station'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $stations = Station::whereIn('id', $stationIds)->get()->keyBy('id');

        return $logs->map(function (TransactionLog $log) use ($stations) {
            $session = $log->session;
            $stationId = $log->station_id ?? $log->next_station_id;
            $station = $log->station ?? $stations->get($stationId);
            $alias = $session?->alias ?? '—';
            $stationName = $station?->name ?? '—';

            // Only indicate "priority lane" when the client has a priority classification (PWD/Senior/Pregnant), not when the program is merely priority-first.
            $isPriority = $session?->isPriorityCategory() ?? false;

            $message = match ($log->action_type) {
                'bind' => "{$alias} registered at triage",
                'call' => $isPriority ? "{$alias} called from priority lane" : "{$alias} called",
                'check_in' => "{$alias} arrived (serving)",
                default => "{$alias} — {$log->action_type}",
            };

            return [
                'station_name' => $stationName,
                'message' => $message,
                'alias' => $alias,
                'action_type' => $log->action_type,
                'created_at' => $log->created_at?->toIso8601String() ?? now()->toIso8601String(),
            ];
        })->values()->all();
    }
}
