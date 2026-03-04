<?php

namespace App\Services;

use App\Models\Program;
use App\Models\Session;
use App\Models\Station;
use App\Models\TransactionLog;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Per 08-API-SPEC-PHASE1 §4: Station queue data.
 * Per plan: priority lane (PWD/Senior/Pregnant), priority_first toggle, balance_mode (fifo|alternate).
 */
class StationQueueService
{
    private static function isPriority(Session $s): bool
    {
        return $s->isPriorityCategory();
    }

    private function getEffectivePriorityFirst(Station $station): bool
    {
        $station->loadMissing('program');
        if ($station->priority_first_override !== null) {
            return (bool) $station->priority_first_override;
        }

        return $station->program?->getPriorityFirst() ?? true;
    }

    /**
     * Get queue data for a station (serving array, waiting, stats).
     * serving = sessions in 'serving' or 'called' (one card per client).
     * waiting = ordered by priority lane logic (priority_first + balance_mode).
     */
    public function getQueueForStation(Station $station): array
    {
        $station->loadMissing('program');

        $sessions = Session::query()
            ->where('program_id', $station->program_id)
            ->where('current_station_id', $station->id)
            ->whereIn('status', ['waiting', 'called', 'serving'])
            ->with(['serviceTrack.trackSteps.process', 'token'])
            ->get();

        $servingAndCalled = $sessions
            ->whereIn('status', ['called', 'serving'])
            ->sortBy(fn (Session $s) => match ($s->status) {
                'called' => 0,
                'serving' => 1,
                default => 2,
            })
            ->values();

        $waitingSessions = $sessions->where('status', 'waiting')->values();
        $priorityFirst = $this->getEffectivePriorityFirst($station);
        $balanceMode = $station->program?->getBalanceMode() ?? 'fifo';
        $ordered = $this->orderWaiting($station, $waitingSessions, $priorityFirst, $balanceMode);

        $stats = $this->computeStats($station);
        $clientCapacity = (int) ($station->client_capacity ?? 1);
        $servingCount = $servingAndCalled->count();
        $noShowTimerSeconds = $station->program?->getNoShowTimerSeconds() ?? 10;

        $waitingFormatted = $ordered->map(fn (Session $s) => $this->formatWaitingSession($s))->all();
        $first = $ordered->first();

        $program = $station->program;
        $requirePermissionBeforeOverride = $program ? $program->getRequirePermissionBeforeOverride() : true;
        $priorityWaitingCount = $ordered->filter(fn (Session $s) => self::isPriority($s))->count();
        $nextIsRegular = $first && ! self::isPriority($first);
        $callNextRequiresOverride = $requirePermissionBeforeOverride
            && ! $priorityFirst
            && $nextIsRegular
            && $priorityWaitingCount > 0;

        return [
            'station' => [
                'id' => $station->id,
                'name' => $station->name,
                'client_capacity' => $clientCapacity,
                'serving_count' => $servingCount,
            ],
            'display_audio_muted' => $station->getDisplayAudioMuted(),
            'display_audio_volume' => $station->getDisplayAudioVolume(),
            'tts_source' => $station->getTtsSource(),
            'display_tts_voice' => $station->getDisplayTtsVoice(),
            'priority_first' => $priorityFirst,
            'require_permission_before_override' => $requirePermissionBeforeOverride,
            'call_next_requires_override' => $callNextRequiresOverride,
            'balance_mode' => $balanceMode,
            'waiting_priority' => $ordered->filter(fn (Session $s) => self::isPriority($s))->map(fn (Session $s) => $this->formatWaitingSession($s))->values()->all(),
            'waiting_regular' => $ordered->filter(fn (Session $s) => ! self::isPriority($s))->map(fn (Session $s) => $this->formatWaitingSession($s))->values()->all(),
            'serving' => $servingAndCalled->map(fn (Session $s) => $this->formatServingSession($s))->all(),
            'no_show_timer_seconds' => $noShowTimerSeconds,
            'waiting' => $waitingFormatted,
            'next_to_call' => $first ? ['session_id' => $first->id, 'alias' => $first->alias] : null,
            'stats' => [
                'total_waiting' => $stats['total_waiting'],
                'total_served_today' => $stats['total_served_today'],
                'avg_service_time_minutes' => $stats['avg_service_time_minutes'],
            ],
        ];
    }

    /**
     * Order waiting sessions by priority_first and balance_mode.
     *
     * @param  \Illuminate\Support\Collection<int, Session>  $waiting
     * @return \Illuminate\Support\Collection<int, Session>
     */
    private function orderWaiting(Station $station, $waiting, bool $priorityFirst, string $balanceMode): Collection
    {
        if ($waiting->isEmpty()) {
            return $waiting;
        }

        $byQueuedAt = fn (Session $a, Session $b) => ($a->queued_at_station ?? $a->started_at ?? now())
            <=> ($b->queued_at_station ?? $b->started_at ?? now());

        if ($priorityFirst) {
            return $waiting
                ->sortBy([
                    fn (Session $a, Session $b) => (self::isPriority($b) ? 1 : 0) <=> (self::isPriority($a) ? 1 : 0),
                    $byQueuedAt,
                ])
                ->values();
        }

        if ($balanceMode === 'fifo') {
            return $waiting->sortBy(fn (Session $s) => $s->queued_at_station ?? $s->started_at ?? now())->values();
        }

        return $this->orderByAlternate($station, $waiting);
    }

    /**
     * Alternate mode: use ratio to determine next. Put next first, rest by queued_at.
     *
     * @param  \Illuminate\Support\Collection<int, Session>  $waiting
     * @return \Illuminate\Support\Collection<int, Session>
     */
    private function orderByAlternate(Station $station, $waiting): Collection
    {
        $program = $station->program;
        [$pRatio, $rRatio] = $program ? $program->getAlternateRatio() : [1, 1];

        $recentCalls = TransactionLog::query()
            ->where('station_id', $station->id)
            ->where('action_type', 'call')
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        $priorityCalled = 0;
        $regularCalled = 0;
        foreach ($recentCalls as $log) {
            $session = Session::find($log->session_id);
            if ($session && self::isPriority($session)) {
                $priorityCalled++;
            } else {
                $regularCalled++;
            }
        }

        $priorityWaiting = $waiting->filter(fn (Session $s) => self::isPriority($s))
            ->sortBy(fn (Session $s) => $s->queued_at_station ?? $s->started_at ?? now())->values();
        $regularWaiting = $waiting->filter(fn (Session $s) => ! self::isPriority($s))
            ->sortBy(fn (Session $s) => $s->queued_at_station ?? $s->started_at ?? now())->values();

        $preferRegular = $regularCalled > 0
            && ($priorityCalled / $regularCalled) >= ($pRatio / $rRatio);

        $next = null;
        if ($preferRegular && $regularWaiting->isNotEmpty()) {
            $next = $regularWaiting->first();
        } elseif ($priorityWaiting->isNotEmpty()) {
            $next = $priorityWaiting->first();
        } elseif ($regularWaiting->isNotEmpty()) {
            $next = $regularWaiting->first();
        }

        if (! $next) {
            return $waiting->sortBy(fn (Session $s) => $s->queued_at_station ?? $s->started_at ?? now())->values();
        }

        $rest = $waiting->filter(fn (Session $s) => $s->id !== $next->id)
            ->sortBy(fn (Session $s) => $s->queued_at_station ?? $s->started_at ?? now())
            ->values();

        return collect([$next])->concat($rest);
    }

    /**
     * List stations for the active program with queue_count and assigned_staff.
     */
    public function listStationsForActiveProgram(): array
    {
        $program = Program::query()->where('is_active', true)->first();

        if (! $program) {
            return ['stations' => []];
        }

        $stations = $program->stations()
            ->with('assignedStaff')
            ->orderBy('id')
            ->get();

        $stationIds = $stations->pluck('id')->all();
        $queueCounts = Session::query()
            ->whereIn('current_station_id', $stationIds)
            ->whereIn('status', ['waiting', 'called', 'serving'])
            ->selectRaw('current_station_id, count(*) as cnt')
            ->groupBy('current_station_id')
            ->pluck('cnt', 'current_station_id')
            ->all();

        return [
            'stations' => $stations->map(function (Station $s) use ($queueCounts) {
                return [
                    'id' => $s->id,
                    'name' => $s->name,
                    'is_active' => $s->is_active,
                    'queue_count' => $queueCounts[$s->id] ?? 0,
                    'assigned_staff' => $s->assignedStaff->map(fn ($u) => [
                        'id' => $u->id,
                        'name' => $u->name,
                    ])->values()->all(),
                ];
            })->values()->all(),
        ];
    }

    /**
     * Resolve current process for a session from track step at current_step_order.
     * Per flexiqueue-ui3: 1-station-many-process — show which queue/process each client is in.
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

    private function formatServingSession(Session $s): array
    {
        $track = $s->serviceTrack;
        // Per ISSUES-ELABORATION §4: custom-override sessions use override step count so station UI shows Complete on last step
        $overrideSteps = $s->override_steps ?? [];
        $totalSteps = count($overrideSteps) > 0
            ? count($overrideSteps)
            : ($track ? $track->trackSteps()->count() : 1);

        $process = $this->currentProcessForSession($s);

        return [
            'session_id' => $s->id,
            'alias' => $s->alias,
            'track' => $track?->name ?? 'Unknown',
            'client_category' => $s->client_category ?? 'Regular',
            'status' => $s->status,
            'current_step_order' => $s->current_step_order ?? 1,
            'total_steps' => $totalSteps,
            'started_at' => $s->started_at?->toIso8601String(),
            'no_show_attempts' => $s->no_show_attempts ?? 0,
            'process_id' => $process ? $process['process_id'] : null,
            'process_name' => $process ? $process['process_name'] : null,
        ];
    }

    private function formatWaitingSession(Session $s): array
    {
        $track = $s->serviceTrack;
        $queuedAt = $s->queued_at_station ?? $s->started_at;
        $process = $this->currentProcessForSession($s);

        return [
            'session_id' => $s->id,
            'alias' => $s->alias,
            'track' => $track?->name ?? 'Unknown',
            'client_category' => $s->client_category ?? 'Regular',
            'status' => $s->status,
            'station_queue_position' => $s->station_queue_position,
            'queued_at' => $queuedAt?->toIso8601String(),
            'process_id' => $process ? $process['process_id'] : null,
            'process_name' => $process ? $process['process_name'] : null,
        ];
    }

    private function computeStats(Station $station): array
    {
        $today = Carbon::today();

        $totalWaiting = Session::query()
            ->where('current_station_id', $station->id)
            ->whereIn('status', ['waiting', 'called', 'serving'])
            ->count();

        $checkInsToday = TransactionLog::query()
            ->where('station_id', $station->id)
            ->where('action_type', 'check_in')
            ->whereDate('created_at', $today)
            ->get();

        $totalServedToday = $checkInsToday->count();

        $durations = new Collection;
        foreach ($checkInsToday as $checkIn) {
            $leaveLog = TransactionLog::query()
                ->where('session_id', $checkIn->session_id)
                ->where('created_at', '>', $checkIn->created_at)
                ->where(function ($q) use ($station) {
                    $q->where(function ($q2) use ($station) {
                        $q2->where('action_type', 'transfer')
                            ->where('previous_station_id', $station->id);
                    })->orWhere(function ($q2) use ($station) {
                        $q2->where('action_type', 'complete')
                            ->where('station_id', $station->id);
                    });
                })
                ->orderBy('created_at')
                ->first();

            if ($leaveLog) {
                $start = $checkIn->created_at ?? Carbon::now();
                $end = $leaveLog->created_at ?? Carbon::now();
                $durations->push($start->diffInMinutes($end));
            }
        }

        $avgServiceTime = $durations->isEmpty() ? 0 : round($durations->avg(), 1);

        return [
            'total_waiting' => $totalWaiting,
            'total_served_today' => $totalServedToday,
            'avg_service_time_minutes' => $avgServiceTime,
        ];
    }

    /**
     * Program-level stats for mobile layout footer (Queue / Processed today).
     * Used by Station, Triage, and Track Overrides pages.
     */
    public function getProgramFooterStats(?Program $program): array
    {
        if (! $program) {
            return ['queue_count' => 0, 'processed_today' => 0];
        }

        $today = Carbon::today();

        $queueCount = Session::query()
            ->where('program_id', $program->id)
            ->whereIn('status', ['waiting', 'called', 'serving'])
            ->count();

        $processedToday = Session::query()
            ->where('program_id', $program->id)
            ->where('status', 'completed')
            ->whereDate('completed_at', $today)
            ->count();

        return [
            'queue_count' => $queueCount,
            'processed_today' => $processedToday,
        ];
    }
}
