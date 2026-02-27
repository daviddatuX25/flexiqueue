<?php

namespace App\Services;

use App\Models\Program;
use App\Models\TransactionLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Per docs/plans/STAFF-DASHBOARD-PLAN.md: metrics for staff dashboard (sessions served, activity, station queue).
 */
class StaffDashboardService
{
    public function getMetricsForUser(User $user): array
    {
        $today = Carbon::today();

        $sessionsServedToday = TransactionLog::query()
            ->where('staff_user_id', $user->id)
            ->where('action_type', 'check_in')
            ->whereDate('created_at', $today)
            ->count();

        $stationSummary = $this->getStationSummary($user);
        $recentActivityCounts = $this->getRecentActivityCounts($user->id, $today);

        $averageMinutes = $this->getAverageTimePerClientToday($user->id, $today);

        return [
            'sessions_served_today' => $sessionsServedToday,
            'average_time_per_client_minutes' => $averageMinutes,
            'station' => $stationSummary,
            'activity_counts_today' => $recentActivityCounts,
        ];
    }

    private function getStationSummary(User $user): ?array
    {
        $program = Program::where('is_active', true)->first();
        if (! $program) {
            return null;
        }

        $station = $user->assignedStationForProgram($program->id);
        if (! $station) {
            return [
                'name' => null,
                'queue_count' => 0,
                'message' => 'No station assigned',
            ];
        }

        $queueCount = \App\Models\Session::query()
            ->where('current_station_id', $station->id)
            ->whereIn('status', ['waiting', 'called', 'serving'])
            ->count();

        return [
            'name' => $station->name,
            'queue_count' => $queueCount,
            'message' => null,
        ];
    }

    private function getRecentActivityCounts(int $userId, Carbon $day): array
    {
        $counts = TransactionLog::query()
            ->where('staff_user_id', $userId)
            ->whereDate('created_at', $day)
            ->select('action_type', DB::raw('count(*) as count'))
            ->groupBy('action_type')
            ->pluck('count', 'action_type')
            ->all();

        return $counts;
    }

    /**
     * Average minutes from check_in to transfer/complete for sessions this staff served today.
     */
    private function getAverageTimePerClientToday(int $userId, Carbon $day): ?float
    {
        $checkIns = TransactionLog::query()
            ->where('staff_user_id', $userId)
            ->where('action_type', 'check_in')
            ->whereDate('created_at', $day)
            ->get(['session_id', 'created_at']);

        if ($checkIns->isEmpty()) {
            return null;
        }

        $sessionIds = $checkIns->pluck('session_id')->unique()->all();
        $completes = TransactionLog::query()
            ->where('staff_user_id', $userId)
            ->whereIn('session_id', $sessionIds)
            ->whereIn('action_type', ['transfer', 'complete'])
            ->whereDate('created_at', $day)
            ->get(['session_id', 'created_at'])
            ->keyBy('session_id');

        $durations = [];
        foreach ($checkIns as $log) {
            $end = $completes->get($log->session_id);
            if ($end) {
                $durations[] = $log->created_at->diffInMinutes($end->created_at);
            }
        }

        if (empty($durations)) {
            return null;
        }

        return round(array_sum($durations) / count($durations), 1);
    }
}
