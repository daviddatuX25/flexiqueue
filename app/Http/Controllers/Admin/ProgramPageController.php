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
                    'process_id' => $s->process_id,
                    'process_name' => $s->process?->name,
                    'step_order' => $s->step_order,
                    'is_required' => $s->is_required,
                    'estimated_minutes' => $s->estimated_minutes,
                ])->values()->all(),
            ]);

        $processes = $program->processes()
            ->orderBy('name')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'program_id' => $p->program_id,
                'name' => $p->name,
                'description' => $p->description,
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
                ],
            ],
            'tracks' => $tracks,
            'processes' => $processes,
            'stations' => $stations,
            'stats' => $stats,
        ]);
    }
}
