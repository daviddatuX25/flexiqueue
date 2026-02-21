<?php

namespace App\Services;

use App\Models\Process;
use App\Models\Program;
use App\Models\Session;
use App\Models\TransactionLog;

/**
 * Per PROCESS-STATION-REFACTOR §4.2: Select concrete station for a process when multiple stations serve it.
 */
class StationSelectionService
{
    /**
     * Select station for a process. Returns station_id or null if no candidate.
     *
     * @param  array<string, mixed>|null  $context
     */
    public function selectStationForProcess(int $processId, int $programId, ?array $context = null): ?int
    {
        $process = Process::where('program_id', $programId)->find($processId);
        if (! $process) {
            return null;
        }

        $candidates = $process->activeStations()->pluck('id')->all();
        if (empty($candidates)) {
            return null;
        }

        if (count($candidates) === 1) {
            return (int) $candidates[0];
        }

        $program = Program::find($programId);
        $mode = $program ? $program->getStationSelectionMode() : 'fixed';

        return match ($mode) {
            'shortest_queue' => $this->shortestQueue($candidates),
            'least_busy' => $this->leastBusy($candidates),
            'round_robin' => $this->roundRobin($processId, $candidates),
            'least_recently_served' => $this->leastRecentlyServed($candidates),
            default => $this->shortestQueue($candidates),
        };
    }

    /**
     * Station with fewest waiting (waiting + called + serving).
     *
     * @param  array<int>  $stationIds
     */
    private function shortestQueue(array $stationIds): int
    {
        $counts = Session::query()
            ->whereIn('current_station_id', $stationIds)
            ->whereIn('status', ['waiting', 'called', 'serving'])
            ->selectRaw('current_station_id, count(*) as cnt')
            ->groupBy('current_station_id')
            ->pluck('cnt', 'current_station_id')
            ->all();

        $min = PHP_INT_MAX;
        $selected = $stationIds[0];
        foreach ($stationIds as $id) {
            $cnt = $counts[$id] ?? 0;
            if ($cnt < $min) {
                $min = $cnt;
                $selected = $id;
            }
        }

        return (int) $selected;
    }

    /**
     * Same as shortest_queue for now (productive worker: fewest total load).
     *
     * @param  array<int>  $stationIds
     */
    private function leastBusy(array $stationIds): int
    {
        return $this->shortestQueue($stationIds);
    }

    /**
     * Rotate by last assignment for this process.
     *
     * @param  array<int>  $stationIds
     */
    private function roundRobin(int $processId, array $stationIds): int
    {
        $lastStationId = TransactionLog::query()
            ->whereIn('action_type', ['bind', 'transfer'])
            ->whereNotNull('next_station_id')
            ->whereIn('next_station_id', $stationIds)
            ->orderByDesc('created_at')
            ->value('next_station_id');

        if (! $lastStationId) {
            return (int) $stationIds[0];
        }

        $idx = array_search((int) $lastStationId, array_map('intval', $stationIds));
        if ($idx === false) {
            return (int) $stationIds[0];
        }

        $nextIdx = ($idx + 1) % count($stationIds);

        return (int) $stationIds[$nextIdx];
    }

    /**
     * Station that received a client longest ago.
     *
     * @param  array<int>  $stationIds
     */
    private function leastRecentlyServed(array $stationIds): int
    {
        $lastPerStation = TransactionLog::query()
            ->whereIn('action_type', ['bind', 'transfer'])
            ->whereNotNull('next_station_id')
            ->whereIn('next_station_id', $stationIds)
            ->selectRaw('next_station_id, max(created_at) as last_at')
            ->groupBy('next_station_id')
            ->pluck('last_at', 'next_station_id')
            ->all();

        $oldest = null;
        $oldestId = $stationIds[0];
        foreach ($stationIds as $id) {
            $lastAt = $lastPerStation[$id] ?? null;
            if ($lastAt === null) {
                return (int) $id;
            }
            if ($oldest === null || $lastAt < $oldest) {
                $oldest = $lastAt;
                $oldestId = $id;
            }
        }

        return (int) $oldestId;
    }
}
