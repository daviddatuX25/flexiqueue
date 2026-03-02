<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Models\Session;
use App\Models\ServiceTrack;
use App\Models\Station;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders Admin Programs Index (BD-008) and Program Show with Tracks (BD-009).
 */
class ProgramPageController extends Controller
{
    public function index(): Response
    {
        $programs = Program::query()
            ->orderBy('name')
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
        ]);
    }

    /**
     * Program detail with tracks for BD-009. Per 09-UI-ROUTES-PHASE1 §3.8.
     */
    public function show(Program $program): Response
    {
        try {
            return $this->buildProgramShowResponse($program);
        } catch (\Throwable $e) {
            report($e);

            return Inertia::render('Admin/Programs/Show', [
                'program' => null,
                'tracks' => [],
                'processes' => [],
                'stations' => [],
                'stats' => [
                    'total_sessions' => 0,
                    'active_sessions' => 0,
                    'completed_sessions' => 0,
                ],
                'tab_order' => ['Overview', 'Processes', 'Stations', 'Staff', 'Track', 'Diagram', 'Settings'],
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

        $stations = $program->stations()
            ->with('processes')
            ->orderBy('name')
            ->get()
            ->map(fn (Station $s) => [
                'id' => $s->id,
                'program_id' => $s->program_id,
                'name' => $s->name,
                'capacity' => $s->capacity,
                'client_capacity' => $s->client_capacity ?? 1,
                'priority_first_override' => $s->priority_first_override,
                'is_active' => $s->is_active,
                'created_at' => $s->created_at?->toIso8601String(),
                'process_ids' => $s->processes->pluck('id')->values()->all(),
            ]);

        $settings = $program->settings ?? [];

        $stats = [
            'total_sessions' => Session::where('program_id', $program->id)->count(),
            'active_sessions' => Session::where('program_id', $program->id)->active()->count(),
            'completed_sessions' => Session::where('program_id', $program->id)->whereIn('status', ['completed', 'cancelled', 'no_show'])->count(),
        ];

        return Inertia::render('Admin/Programs/Show', [
            'program' => [
                'id' => $program->id,
                'name' => $program->name,
                'description' => $program->description,
                'is_active' => $program->is_active,
                'is_paused' => $program->is_paused ?? false,
                'created_at' => $program->created_at?->toIso8601String(),
                'settings' => [
                    'no_show_timer_seconds' => (int) ($settings['no_show_timer_seconds'] ?? 10),
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
                    'display_audio_muted' => $program->getDisplayAudioMuted(),
                    'display_audio_volume' => $program->getDisplayAudioVolume(),
                    'display_tts_voice' => $program->getDisplayTtsVoice(),
                    'allow_public_triage' => $program->getAllowPublicTriage(),
                ],
            ],
            'tracks' => $tracks,
            'processes' => $processes,
            'stations' => $stations,
            'stats' => $stats,
            // Tab order for nav (Overview → Processes → Stations → Staff → Track → Settings). Per ISSUES-ELABORATION §13.
            'tab_order' => ['Overview', 'Processes', 'Stations', 'Staff', 'Track', 'Diagram', 'Settings'],
        ]);
    }
}
