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
            ];
        }

        $servingAndCalled = Session::query()
            ->where('program_id', $program->id)
            ->whereIn('status', ['serving', 'called'])
            ->with(['currentStation', 'serviceTrack'])
            ->orderBy('started_at')
            ->get();

        $waiting = Session::query()
            ->where('program_id', $program->id)
            ->where('status', 'waiting')
            ->with('currentStation')
            ->orderBy('station_queue_position')
            ->orderBy('started_at')
            ->get();

        $nowServing = $servingAndCalled->map(fn (Session $s) => [
            'alias' => $s->alias,
            'status' => $s->status,
            'station_name' => $s->currentStation?->name ?? '—',
            'track' => $s->serviceTrack?->name ?? '—',
        ])->values()->all();

        $byStationServing = $servingAndCalled->groupBy('current_station_id');
        $byStationWaiting = $waiting->groupBy('current_station_id');
        $stationIds = $byStationServing->keys()->merge($byStationWaiting->keys())->unique()->filter()->values();

        $waitingByStation = [];
        foreach ($stationIds as $stationId) {
            $station = $byStationServing->get($stationId)?->first()?->currentStation
                ?? $byStationWaiting->get($stationId)?->first()?->currentStation;
            $servingAtStation = $byStationServing->get($stationId) ?? collect();
            $waitingAtStation = $byStationWaiting->get($stationId) ?? collect();
            $waitingByStation[] = [
                'station_name' => $station?->name ?? '—',
                'aliases' => $waitingAtStation->pluck('alias')->values()->all(),
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
            ])->values()->all(),
        ])->values()->all();

        $staffOnline = User::query()
            ->whereNotNull('assigned_station_id')
            ->where('is_active', true)
            ->where('availability_status', 'available')
            ->count();

        return [
            'program_name' => $program->name,
            'date' => now()->format('F j, Y'),
            'now_serving' => $nowServing,
            'waiting_by_station' => $waitingByStation,
            'total_in_queue' => $totalInQueue,
            'station_activity' => $stationActivity,
            'staff_at_stations' => $staffAtStations,
            'staff_online' => $staffOnline,
        ];
    }

    /**
     * Get last N station activities (call, check_in) for display.
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
            ->whereIn('station_id', $stationIds)
            ->whereIn('action_type', ['call', 'check_in'])
            ->with(['session', 'station'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $stations = Station::whereIn('id', $stationIds)->get()->keyBy('id');

        return $logs->map(function (TransactionLog $log) use ($stations) {
            $session = $log->session;
            $station = $log->station ?? $stations->get($log->station_id);
            $alias = $session?->alias ?? '—';
            $stationName = $station?->name ?? '—';

            $isPriority = $session?->isPriorityCategory() ?? false;

            $message = match ($log->action_type) {
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
