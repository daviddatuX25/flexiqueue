<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Models\Session;
use App\Models\ServiceTrack;
use App\Models\SiteShortLink;
use App\Models\Station;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders Admin Programs Index (BD-008) and Program Show with Tracks (BD-009).
 */
class ProgramPageController extends Controller
{
    public function index(Request $request): Response
    {
        $siteId = $request->user()?->site_id;
        $search = trim((string) $request->query('search', ''));
        $search = mb_strlen($search) > 100 ? mb_substr($search, 0, 100) : $search;

        $query = Program::query()->forSite($siteId)->orderBy('name');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%');
            });
        }

        $programs = $query
            ->get()
            ->map(fn (Program $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'is_active' => $p->is_active,
                'is_paused' => $p->is_paused ?? false,
                'created_at' => $p->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Admin/Programs/Index', [
            'programs' => $programs,
            'search' => $search !== '' ? $search : null,
        ]);
    }

    /**
     * Program detail with tracks for BD-009. Per 09-UI-ROUTES-PHASE1 §3.8. Per central-edge B.4: 404 if not in site.
     */
    public function show(Request $request, Program $program): Response
    {
        $siteId = $request->user()?->site_id;
        if ($siteId === null || $program->site_id !== $siteId) {
            abort(404);
        }

        try {
            return $this->buildProgramShowResponse($program);
        } catch (\Throwable $e) {
            report($e);

            return Inertia::render('Admin/Programs/Show', [
                'program' => null,
                'currentProgram' => null,
                'tracks' => [],
                'processes' => [],
                'stations' => [],
                'stats' => [
                    'total_sessions' => 0,
                    'active_sessions' => 0,
                    'completed_sessions' => 0,
                ],
                'tab_order' => ['Overview', 'Processes', 'Stations', 'Staff', 'Track', 'Diagram', 'Settings'],
                'site_slug' => null,
                'app_url' => rtrim(config('app.url'), '/'),
            ]);
        }
    }

    /**
     * Build the Program Show Inertia payload (extracted for try/catch in show()).
     */
    private function buildProgramShowResponse(Program $program): Response
    {
        $tracks = $program->serviceTracks()
            ->with(['trackSteps.process'])
            ->orderBy('name')
            ->get()
            ->map(fn (ServiceTrack $t) => [
                'id' => $t->id,
                'program_id' => $t->program_id,
                'name' => $t->name,
                'description' => $t->description,
                'is_default' => $t->is_default,
                'color_code' => $t->color_code,
                'created_at' => $t->created_at?->toIso8601String(),
                'active_sessions_count' => Session::active()->where('track_id', $t->id)->count(),
                'steps' => $t->trackSteps->map(fn ($s) => [
                    'id' => $s->id,
                    'track_id' => $s->track_id,
                    'station_id' => $s->station_id,
                    'station_name' => $s->station?->name,
                    'process_id' => $s->process_id,
                    'process_name' => $s->process?->name,
                    'step_order' => $s->step_order,
                    'is_required' => $s->is_required,
                    'estimated_minutes' => $s->estimated_minutes,
                ])->values()->all(),
                // Per flexiqueue-5l7: total = sum of step estimated_minutes; travel/queue = 0 for now
                'total_estimated_minutes' => $t->trackSteps->sum(fn ($s) => (int) ($s->estimated_minutes ?? 0)),
                'travel_queue_minutes' => 0,
            ]);

        $processes = $program->processes()
            ->orderBy('name')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'program_id' => $p->program_id,
                'name' => $p->name,
                'description' => $p->description,
                'expected_time_seconds' => $p->expected_time_seconds,
                'created_at' => $p->created_at?->toIso8601String(),
            ]);

        $stationsQuery = $program->stations()
            ->with('processes')
            ->orderBy('name');

        $stations = $stationsQuery
            ->get()
            ->map(function (Station $s) {
                $settings = $s->settings ?? [];
                $stationTtsLanguages = [];
                if (
                    isset($settings['tts']) &&
                    is_array($settings['tts']) &&
                    isset($settings['tts']['languages']) &&
                    is_array($settings['tts']['languages'])
                ) {
                    $stationTtsLanguages = $settings['tts']['languages'];
                }

                return [
                    'id' => $s->id,
                    'program_id' => $s->program_id,
                    'name' => $s->name,
                    'capacity' => $s->capacity,
                    'client_capacity' => $s->client_capacity ?? 1,
                    'priority_first_override' => $s->priority_first_override,
                    'is_active' => $s->is_active,
                    'created_at' => $s->created_at?->toIso8601String(),
                    'process_ids' => $s->processes->pluck('id')->values()->all(),
                    'tts' => [
                        'languages' => $stationTtsLanguages,
                    ],
                ];
            });

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

        $stats = [
            'total_sessions' => Session::where('program_id', $program->id)->count(),
            'active_sessions' => Session::where('program_id', $program->id)->active()->count(),
            'completed_sessions' => Session::where('program_id', $program->id)->whereIn('status', ['completed', 'cancelled', 'no_show'])->count(),
        ];

        $bannerPath = $settings['page_banner_image_path'] ?? null;
        $bannerUrl = $bannerPath && is_string($bannerPath) ? Storage::url($bannerPath) : null;

        $shortLinks = SiteShortLink::query()
            ->where('program_id', $program->id)
            ->get()
            ->map(fn (SiteShortLink $l) => [
                'type' => $l->type,
                'code' => $l->code,
                'url' => rtrim(config('app.url'), '/').'/go/'.$l->code,
                'has_embedded_key' => $l->embedded_key !== null && $l->embedded_key !== '',
            ])
            ->values()
            ->all();

        $programPayload = [
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
                'display_scan_timeout_seconds' => array_key_exists('display_scan_timeout_seconds', $settings)
                    ? (int) $settings['display_scan_timeout_seconds']
                    : 20,
                'display_audio_muted' => $programSettings->getDisplayAudioMuted(),
                'display_audio_volume' => $programSettings->getDisplayAudioVolume(),
                'display_tts_repeat_count' => $programSettings->getDisplayTtsRepeatCount(),
                'display_tts_repeat_delay_ms' => $programSettings->getDisplayTtsRepeatDelayMs(),
                'allow_public_triage' => $programSettings->getAllowPublicTriage(),
                'identity_binding_mode' => $programSettings->getIdentityBindingMode(),
                'enable_display_hid_barcode' => $programSettings->getEnableDisplayHidBarcode(),
                'enable_public_triage_hid_barcode' => $programSettings->getEnablePublicTriageHidBarcode(),
                'enable_display_camera_scanner' => $programSettings->getEnableDisplayCameraScanner(),
                'public_access_key' => $programSettings->getPublicAccessKey(),
                'public_access_expiry_hours' => $programSettings->getPublicAccessExpiryHours(),
                'page_description' => $programSettings->getPageDescription(),
                'page_announcement' => $programSettings->getPageAnnouncement(),
                'page_banner_image_url' => $bannerUrl,
                'tts' => [
                    'active_language' => $programSettings->getTtsActiveLanguage(),
                    'connector' => [
                        'languages' => $connectorLanguages,
                    ],
                ],
            ],
            'short_links' => $shortLinks,
        ];

        $site = $program->site;

        return Inertia::render('Admin/Programs/Show', [
            'program' => $programPayload,
            'currentProgram' => $programPayload,
            'tracks' => $tracks,
            'processes' => $processes,
            'stations' => $stations,
            'stats' => $stats,
            'tab_order' => ['Overview', 'Public Page', 'Processes', 'Stations', 'Staff', 'Track', 'Diagram', 'Settings'],
            'site_slug' => $site->slug,
            'app_url' => rtrim(config('app.url'), '/'),
        ]);
    }
}
