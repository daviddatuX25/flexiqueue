<?php

namespace App\Http\Controllers\Api\Admin;

use App\Events\DisplaySettingsUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProgramRequest;
use App\Http\Requests\UpdateProgramRequest;
use App\Jobs\GenerateStationTtsJob;
use App\Models\Program;
use App\Models\ProgramAccessToken;
use App\Models\SiteShortLink;
use App\Services\ProgramService;
use App\Support\QueueWorkerIdleCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per 08-API-SPEC-PHASE1 §5.1: Program CRUD + activate/deactivate. Auth: role:admin.
 * Per central-edge B.4: programs are scoped by authenticated user's site_id.
 */
class ProgramController extends Controller
{
    public function __construct(
        private ProgramService $programService
    ) {}

    /**
     * List all programs (site-scoped). Per B.4: only programs in the admin's site.
     */
    public function index(Request $request): JsonResponse
    {
        $siteId = $request->user()->site_id;

        $programs = Program::query()
            ->forSite($siteId)
            ->orderBy('name')
            ->get()
            ->map(fn (Program $p) => $this->programResource($p));

        return response()->json(['programs' => $programs]);
    }

    /**
     * Create program. Per spec: 201 with program object. Per B.4: site_id from auth.
     */
    public function store(StoreProgramRequest $request): JsonResponse
    {
        $user = $request->user();
        if ($user->site_id === null) {
            abort(403, 'You must be assigned to a site to create programs.');
        }

        $program = Program::create([
            'site_id' => $user->site_id,
            'name' => $request->validated('name'),
            'description' => $request->validated('description'),
            'is_active' => false,
            'created_by' => $user->id,
        ]);

        return response()->json(['program' => $this->programResource($program)], 201);
    }

    /**
     * Get program details (includes tracks, stations, stats per spec). Per B.4: 404 if not in site.
     */
    public function show(Request $request, Program $program): JsonResponse
    {
        $this->ensureProgramInSite($request, $program);

        $program->loadCount(['serviceTracks', 'stations', 'queueSessions']);

        return response()->json([
            'program' => $this->programResource($program),
            'tracks_count' => $program->service_tracks_count,
            'stations_count' => $program->stations_count,
            'sessions_count' => $program->queue_sessions_count,
        ]);
    }

    /**
     * Update program. Settings merged with existing when provided. Per B.4: 404 if not in site.
     */
    public function update(UpdateProgramRequest $request, Program $program): JsonResponse
    {
        $this->ensureProgramInSite($request, $program);

        $validated = $request->validated();
        $settings = $validated['settings'] ?? null;
        unset($validated['settings']);

        if ($settings !== null) {
            $merged = $program->settings ?? [];
            foreach ($settings as $k => $v) {
                if ($k === 'page_banner_image_path') {
                    continue;
                }
                $merged[$k] = $v;
            }
            $validated['settings'] = $merged;
        }

        $oldKey = $program->settings()->getPublicAccessKey();
        $newKey = isset($validated['settings']['public_access_key']) ? $validated['settings']['public_access_key'] : null;
        $newKey = $newKey === '' ? null : $newKey;
        $keyChanged = ($oldKey ?? '') !== ($newKey ?? '');

        $program->update($validated);

        if ($keyChanged) {
            ProgramAccessToken::query()->where('program_id', $program->id)->delete();
            SiteShortLink::query()
                ->where('program_id', $program->id)
                ->where('type', SiteShortLink::TYPE_PROGRAM_PRIVATE)
                ->delete();
        }

        $program = $program->fresh();
        if ($settings !== null && (array_key_exists('display_audio_muted', $settings) || array_key_exists('display_audio_volume', $settings) || array_key_exists('enable_display_hid_barcode', $settings) || array_key_exists('enable_public_triage_hid_barcode', $settings) || array_key_exists('enable_display_camera_scanner', $settings) || array_key_exists('enable_public_triage_camera_scanner', $settings) || array_key_exists('display_tts_repeat_count', $settings) || array_key_exists('display_tts_repeat_delay_ms', $settings))) {
            event(new DisplaySettingsUpdated(
                $program->id,
                $program->settings()->getDisplayAudioMuted(),
                $program->settings()->getDisplayAudioVolume(),
                $program->settings()->getEnableDisplayHidBarcode(),
                $program->settings()->getEnablePublicTriageHidBarcode(),
                $program->settings()->getEnableDisplayCameraScanner(),
                $program->settings()->getDisplayTtsRepeatCount(),
                $program->settings()->getDisplayTtsRepeatDelayMs(),
                $program->settings()->getEnablePublicTriageCameraScanner(),
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
     * Regenerate TTS for all stations of the program (connector + station phrase). Per B.4: 404 if not in site.
     */
    public function regenerateStationTts(Request $request, Program $program): JsonResponse
    {
        $this->ensureProgramInSite($request, $program);

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
     * Activate program (deactivates current active). Per B.4: 404 if not in site.
     * Per ISSUES-ELABORATION §16: returns 422 with missing[] if pre-session checks fail.
     */
    public function activate(Request $request, Program $program): JsonResponse
    {
        $this->ensureProgramInSite($request, $program);

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

        $payload = ['program' => $this->programResource($program)];
        $staffInMultipleCount = $this->programService->getStaffInMultipleActiveProgramsCount();
        if ($staffInMultipleCount > 0) {
            $payload['warning'] = $staffInMultipleCount === 1
                ? '1 staff member is assigned to more than one active program. They will need to choose one program when using Station or Triage.'
                : "{$staffInMultipleCount} staff members are assigned to more than one active program. They will need to choose one program when using Station or Triage.";
        }

        return response()->json($payload);
    }

    /**
     * Pause program. Queue times do not count while paused. Per B.4: 404 if not in site.
     */
    public function pause(Request $request, Program $program): JsonResponse
    {
        $this->ensureProgramInSite($request, $program);

        try {
            $program = $this->programService->pause($program);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }

        return response()->json(['program' => $this->programResource($program)]);
    }

    /**
     * Resume program. Queue times count again. Per B.4: 404 if not in site.
     */
    public function resume(Request $request, Program $program): JsonResponse
    {
        $this->ensureProgramInSite($request, $program);

        try {
            $program = $this->programService->resume($program);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }

        return response()->json(['program' => $this->programResource($program)]);
    }

    /**
     * Deactivate program. 400 if active sessions exist. Per B.4: 404 if not in site.
     */
    public function deactivate(Request $request, Program $program): JsonResponse
    {
        $this->ensureProgramInSite($request, $program);

        try {
            $program = $this->programService->deactivate($program);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }

        ProgramAccessToken::query()->where('program_id', $program->id)->delete();

        return response()->json(['program' => $this->programResource($program)]);
    }

    /**
     * Delete program. 400 if any sessions exist. Per B.4: 404 if not in site.
     */
    public function destroy(Request $request, Program $program): JsonResponse
    {
        $this->ensureProgramInSite($request, $program);

        ProgramAccessToken::query()->where('program_id', $program->id)->delete();

        try {
            $this->programService->delete($program);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }

        return response()->json(null, 204);
    }

    /**
     * Per central-edge B.4: ensure admin has a site and program belongs to it. 403 if no site, 404 if wrong site.
     */
    private function ensureProgramInSite(Request $request, Program $program): void
    {
        $siteId = $request->user()->site_id;
        if ($siteId === null) {
            abort(403, 'You must be assigned to a site to access this resource.');
        }
        if ($program->site_id !== $siteId) {
            abort(404);
        }
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
            'slug' => $program->slug,
            'description' => $program->description,
            'is_active' => $program->is_active,
            'is_paused' => $program->is_paused ?? false,
            'is_published' => $program->is_published ?? true,
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
                'allow_unverified_entry' => $programSettings->getAllowUnverifiedEntry(),
                'identity_binding_mode' => $programSettings->getIdentityBindingMode(),
                'public_access_key' => $programSettings->getPublicAccessKey(),
                'public_access_expiry_hours' => $programSettings->getPublicAccessExpiryHours(),
                'page_description' => $programSettings->getPageDescription(),
                'page_announcement' => $programSettings->getPageAnnouncement(),
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
