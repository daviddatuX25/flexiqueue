<?php

namespace App\Http\Controllers\Api\Admin;

use App\Events\DisplaySettingsUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProgramRequest;
use App\Http\Requests\UpdateProgramRequest;
use App\Jobs\GenerateStationTtsJob;
use App\Models\Program;
use App\Services\ProgramService;
use App\Support\QueueWorkerIdleCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per 08-API-SPEC-PHASE1 §5.1: Program CRUD + activate/deactivate. Auth: role:admin.
 */
class ProgramController extends Controller
{
    public function __construct(
        private ProgramService $programService
    ) {}

    /**
     * List all programs.
     */
    public function index(): JsonResponse
    {
        $programs = Program::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Program $p) => $this->programResource($p));

        return response()->json(['programs' => $programs]);
    }

    /**
     * Create program. Per spec: 201 with program object.
     */
    public function store(StoreProgramRequest $request): JsonResponse
    {
        $program = Program::create([
            'name' => $request->validated('name'),
            'description' => $request->validated('description'),
            'is_active' => false,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['program' => $this->programResource($program)], 201);
    }

    /**
     * Get program details (includes tracks, stations, stats per spec).
     */
    public function show(Program $program): JsonResponse
    {
        $program->loadCount(['serviceTracks', 'stations', 'queueSessions']);

        return response()->json([
            'program' => $this->programResource($program),
            'tracks_count' => $program->service_tracks_count,
            'stations_count' => $program->stations_count,
            'sessions_count' => $program->queue_sessions_count,
        ]);
    }

    /**
     * Update program. Settings merged with existing when provided.
     */
    public function update(UpdateProgramRequest $request, Program $program): JsonResponse
    {
        $validated = $request->validated();
        $settings = $validated['settings'] ?? null;
        unset($validated['settings']);

        if ($settings !== null) {
            $merged = $program->settings ?? [];
            foreach ($settings as $k => $v) {
                $merged[$k] = $v;
            }
            $validated['settings'] = $merged;
        }

        $program->update($validated);

        $program = $program->fresh();
        if ($settings !== null && (array_key_exists('display_audio_muted', $settings) || array_key_exists('display_audio_volume', $settings) || array_key_exists('enable_display_hid_barcode', $settings) || array_key_exists('enable_public_triage_hid_barcode', $settings) || array_key_exists('enable_display_camera_scanner', $settings) || array_key_exists('display_tts_repeat_count', $settings) || array_key_exists('display_tts_repeat_delay_ms', $settings))) {
            event(new DisplaySettingsUpdated(
                $program->settings()->getDisplayAudioMuted(),
                $program->settings()->getDisplayAudioVolume(),
                $program->settings()->getEnableDisplayHidBarcode(),
                $program->settings()->getEnablePublicTriageHidBarcode(),
                $program->settings()->getEnableDisplayCameraScanner(),
                $program->settings()->getDisplayTtsRepeatCount(),
                $program->settings()->getDisplayTtsRepeatDelayMs(),
            ));
        }
        $requiresRegeneration = false;
        if ($settings !== null && array_key_exists('tts', $settings)) {
            $autoGenerateStationTts = $program->settings['tts']['auto_generate_station_tts'] ?? true;
            if ($autoGenerateStationTts) {
                $stations = $program->stations()->get();
                foreach ($stations as $station) {
                    $langs = $station->settings['tts']['languages'] ?? [];
                    foreach ($langs as $config) {
                        if (is_array($config) && (! empty($config['audio_path']) || ($config['status'] ?? '') === 'ready')) {
                            $requiresRegeneration = true;
                            break 2;
                        }
                    }
                }
            }
        }

        $payload = ['program' => $this->programResource($program)];
        if ($requiresRegeneration) {
            $payload['requires_regeneration'] = true;
        }

        return response()->json($payload);
    }

    /**
     * Regenerate TTS for all stations of the program (connector + station phrase). Called after user confirms regeneration prompt.
     */
    public function regenerateStationTts(Program $program): JsonResponse
    {
        $workerIdle = QueueWorkerIdleCheck::appearsIdle();
        $useSync = $workerIdle && config('tts.allow_sync_when_queue_unavailable', false);

        foreach ($program->stations()->get() as $station) {
            if ($useSync) {
                GenerateStationTtsJob::dispatchSync($station);
            } else {
                GenerateStationTtsJob::dispatch($station);
            }
        }

        return response()->json(['message' => 'Station TTS regeneration started.']);
    }

    /**
     * Activate program (deactivates current active).
     * Per ISSUES-ELABORATION §16: returns 422 with missing[] if pre-session checks fail.
     */
    public function activate(Program $program): JsonResponse
    {
        $check = $this->programService->canActivate($program);
        if (! $check['can_activate']) {
            $messages = [
                'no_stations' => 'Add at least one station.',
                'no_processes_with_stations' => 'Add at least one process and assign it to a station.',
                'no_staff_assigned' => 'Assign at least one staff to a station.',
                'no_tracks' => 'Create at least one track.',
            ];
            $message = implode(' ', array_map(fn ($key) => $messages[$key] ?? $key, $check['missing']));

            return response()->json([
                'message' => $message,
                'missing' => $check['missing'],
            ], 422);
        }

        try {
            $program = $this->programService->activate($program);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['program' => $this->programResource($program)]);
    }

    /**
     * Pause program. Queue times do not count while paused.
     */
    public function pause(Program $program): JsonResponse
    {
        try {
            $program = $this->programService->pause($program);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }

        return response()->json(['program' => $this->programResource($program)]);
    }

    /**
     * Resume program. Queue times count again.
     */
    public function resume(Program $program): JsonResponse
    {
        try {
            $program = $this->programService->resume($program);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }

        return response()->json(['program' => $this->programResource($program)]);
    }

    /**
     * Deactivate program. 400 if active sessions exist.
     */
    public function deactivate(Request $request, Program $program): JsonResponse
    {
        try {
            $program = $this->programService->deactivate($program);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }

        return response()->json(['program' => $this->programResource($program)]);
    }

    /**
     * Delete program. 400 if any sessions exist.
     */
    public function destroy(Program $program): JsonResponse
    {
        try {
            $this->programService->delete($program);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }

        return response()->json(null, 204);
    }

    private function programResource(Program $program): array
    {
        $settings = $program->settings ?? [];
        $programSettings = $program->settings();
        $connectorLanguages = [];
        if (
            isset($settings['tts']) &&
            is_array($settings['tts']) &&
            isset($settings['tts']['connector']) &&
            is_array($settings['tts']['connector']) &&
            isset($settings['tts']['connector']['languages']) &&
            is_array($settings['tts']['connector']['languages'])
        ) {
            $connectorLanguages = $settings['tts']['connector']['languages'];
        }

        return [
            'id' => $program->id,
            'name' => $program->name,
            'description' => $program->description,
            'is_active' => $program->is_active,
            'is_paused' => $program->is_paused ?? false,
            'created_at' => $program->created_at?->toIso8601String(),
            'settings' => [
                'no_show_timer_seconds' => (int) ($settings['no_show_timer_seconds'] ?? 10),
                'max_no_show_attempts' => $programSettings->getMaxNoShowAttempts(),
                'require_permission_before_override' => (bool) ($settings['require_permission_before_override'] ?? true),
                'priority_first' => (bool) ($settings['priority_first'] ?? true),
                'balance_mode' => $settings['balance_mode'] ?? 'fifo',
                'station_selection_mode' => $settings['station_selection_mode'] ?? 'fixed',
                'alternate_ratio' => [
                    (int) (($settings['alternate_ratio'] ?? [1, 1])[0] ?? 1),
                    (int) (($settings['alternate_ratio'] ?? [1, 1])[1] ?? 1),
                ],
                'alternate_priority_first' => (bool) ($settings['alternate_priority_first'] ?? true),
                'display_scan_timeout_seconds' => $programSettings->getDisplayScanTimeoutSeconds(),
                'display_audio_muted' => $programSettings->getDisplayAudioMuted(),
                'display_audio_volume' => $programSettings->getDisplayAudioVolume(),
                'display_tts_repeat_count' => $programSettings->getDisplayTtsRepeatCount(),
                'display_tts_repeat_delay_ms' => $programSettings->getDisplayTtsRepeatDelayMs(),
                'allow_public_triage' => $programSettings->getAllowPublicTriage(),
                'identity_binding_mode' => $programSettings->getIdentityBindingMode(),
                'tts' => [
                    'active_language' => $programSettings->getTtsActiveLanguage(),
                    'auto_generate_station_tts' => ($settings['tts']['auto_generate_station_tts'] ?? true) === true,
                    'connector' => [
                        'languages' => $connectorLanguages,
                    ],
                ],
            ],
        ];
    }
}
