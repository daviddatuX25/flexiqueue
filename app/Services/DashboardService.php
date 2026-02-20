<?php

namespace App\Services;

use App\Models\Program;
use App\Models\Session;
use App\Models\Station;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Per 08-API-SPEC-PHASE1 §6.1: Dashboard stats and station detail for admin.
 */
class DashboardService
{
    /**
     * Get dashboard stats. Per 08-API-SPEC §6.1 response shape.
     */
    public function getStats(): array
    {
        $program = Program::query()->where('is_active', true)->first();

        if (! $program) {
            return [
                'active_program' => null,
                'sessions' => [
                    'active' => 0,
                    'waiting' => 0,
                    'serving' => 0,
                    'completed_today' => 0,
                    'cancelled_today' => 0,
                    'no_show_today' => 0,
                ],
                'stations' => [
                    'total' => 0,
                    'active' => 0,
                    'with_queue' => 0,
                ],
                'staff_online' => 0,
                'by_track' => [],
            ];
        }

        $programId = $program->id;
        $today = Carbon::today();
        $baseQuery = Session::query()->where('program_id', $programId);

        $waiting = (clone $baseQuery)->where('status', 'waiting')->count();
        $called = (clone $baseQuery)->where('status', 'called')->count();
        $serving = (clone $baseQuery)->where('status', 'serving')->count();
        $active = $waiting + $called + $serving;

        $completedToday = (clone $baseQuery)
            ->where('status', 'completed')
            ->whereDate('completed_at', $today)
            ->count();

        $cancelledToday = (clone $baseQuery)
            ->where('status', 'cancelled')
            ->whereDate('completed_at', $today)
            ->count();

        $noShowToday = (clone $baseQuery)
            ->where('status', 'no_show')
            ->whereDate('completed_at', $today)
            ->count();

        $stations = $program->stations;
        $stationIds = $stations->pluck('id')->all();
        $stationsWithQueue = Session::query()
            ->whereIn('current_station_id', $stationIds)
            ->whereIn('status', ['waiting', 'called', 'serving'])
            ->distinct()
            ->pluck('current_station_id')
            ->filter()
            ->count();

        $staffOnline = User::query()
            ->whereNotNull('assigned_station_id')
            ->where('is_active', true)
            ->where('availability_status', 'available')
            ->count();

        $byTrack = $this->getByTrack($programId);

        return [
            'active_program' => [
                'id' => $program->id,
                'name' => $program->name,
            ],
            'sessions' => [
                'active' => $active,
                'waiting' => $waiting,
                'serving' => $called + $serving,
                'completed_today' => $completedToday,
                'cancelled_today' => $cancelledToday,
                'no_show_today' => $noShowToday,
            ],
            'stations' => [
                'total' => $stations->count(),
                'active' => $stations->where('is_active', true)->count(),
                'with_queue' => $stationsWithQueue,
            ],
            'staff_online' => $staffOnline,
            'by_track' => $byTrack,
        ];
    }

    /**
     * Get stations for dashboard table: queue_count, assigned_staff, current_client, is_active.
     */
    public function getDashboardStations(): array
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

        $servingSessions = Session::query()
            ->whereIn('current_station_id', $stationIds)
            ->whereIn('status', ['called', 'serving'])
            ->orderByRaw("CASE status WHEN 'serving' THEN 0 ELSE 1 END")
            ->orderBy('started_at')
            ->get();

        $currentClients = [];
        foreach ($servingSessions as $s) {
            $sid = $s->current_station_id;
            if ($sid && ! isset($currentClients[$sid])) {
                $currentClients[$sid] = $s->alias;
            }
        }

        return [
            'stations' => $stations->map(function (Station $s) use ($queueCounts, $currentClients) {
                return [
                    'id' => $s->id,
                    'name' => $s->name,
                    'is_active' => $s->is_active,
                    'queue_count' => $queueCounts[$s->id] ?? 0,
                    'current_client' => $currentClients[$s->id] ?? null,
                    'assigned_staff' => $s->assignedStaff->map(fn ($u) => [
                        'id' => $u->id,
                        'name' => $u->name,
                    ])->values()->all(),
                ];
            })->values()->all(),
        ];
    }

    private function getByTrack(int $programId): array
    {
        $sessions = Session::query()
            ->where('program_id', $programId)
            ->whereIn('status', ['waiting', 'called', 'serving'])
            ->with('serviceTrack')
            ->get();

        return $sessions
            ->groupBy('track_id')
            ->map(fn (Collection $group, $trackId) => [
                'track_name' => $group->first()?->serviceTrack?->name ?? 'Unknown',
                'count' => $group->count(),
            ])
            ->values()
            ->all();
    }
}
