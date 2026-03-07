<?php

namespace App\Services;

use App\Models\Session;
use App\Models\Station;
use App\Models\Token;
use App\Models\TransactionLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Analytics aggregations for Admin Analytics page.
 * All methods accept optional program_id, track_id, and date range (from, to) as Y-m-d.
 */
class AnalyticsService
{
    /**
     * Apply common filters to a query on queue_sessions (alias qs).
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  array{program_id?: int|null, track_id?: int|null, from?: string, to?: string}  $filters
     */
    private function applySessionFilters($query, array $filters): void
    {
        if (! empty($filters['program_id'])) {
            $query->where('queue_sessions.program_id', (int) $filters['program_id']);
        }
        if (! empty($filters['track_id'])) {
            $query->where('queue_sessions.track_id', (int) $filters['track_id']);
        }
        if (! empty($filters['from'])) {
            $query->whereDate('queue_sessions.started_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('queue_sessions.started_at', '<=', $filters['to']);
        }
    }

    /**
     * KPI summary for the strip. Includes trend vs previous equivalent period.
     *
     * @param  array{program_id?: int|null, track_id?: int|null, from?: string, to?: string}  $filters
     * @return array{total_clients_served: int, median_wait_minutes: float|null, p90_wait_minutes: float|null, completion_rate: float, active_sessions: int, trend_total: int, trend_median_wait: float|null, trend_completion_rate: float|null}
     */
    public function getSummary(array $filters): array
    {
        $from = isset($filters['from']) ? Carbon::parse($filters['from']) : Carbon::today()->subDays(29);
        $to = isset($filters['to']) ? Carbon::parse($filters['to']) : Carbon::today();
        $days = max(1, $from->diffInDays($to) + 1);
        $prevFrom = $from->copy()->subDays($days);
        $prevTo = $from->copy()->subDay();

        $base = Session::query()->from('queue_sessions');
        $this->applySessionFilters($base, array_merge($filters, ['from' => $from->toDateString(), 'to' => $to->toDateString()]));

        $totalClientsServed = (clone $base)->where('status', 'completed')->whereNotNull('completed_at')->count();
        $completed = (clone $base)->whereIn('status', ['completed', 'cancelled', 'no_show'])->whereNotNull('completed_at');
        $totalEnded = $completed->count();
        $totalStarted = (clone $base)->count();
        $completionRate = $totalStarted > 0 ? round(($totalClientsServed / $totalStarted) * 100, 1) : 0.0;

        $waitMinutes = $this->getWaitTimeMinutesForSessions($filters);
        $medianWait = $this->percentile($waitMinutes, 50);
        $p90Wait = $this->percentile($waitMinutes, 90);

        $activeSessions = Session::query()
            ->whereIn('status', ['waiting', 'called', 'serving']);
        $this->applySessionFilters($activeSessions, $filters);
        $activeSessions = $activeSessions->count();

        $prevFilters = array_merge($filters, ['from' => $prevFrom->toDateString(), 'to' => $prevTo->toDateString()]);
        $prevTotal = (clone Session::query())->from('queue_sessions');
        $this->applySessionFilters($prevTotal, $prevFilters);
        $prevTotal = $prevTotal->where('status', 'completed')->whereNotNull('completed_at')->count();
        $prevWait = $this->getWaitTimeMinutesForSessions($prevFilters);
        $prevMedian = $this->percentile($prevWait, 50);
        $prevStarted = (clone Session::query())->from('queue_sessions');
        $this->applySessionFilters($prevStarted, $prevFilters);
        $prevStarted = $prevStarted->count();
        $prevCompletion = $prevStarted > 0 ? round(($prevTotal / $prevStarted) * 100, 1) : null;

        return [
            'total_clients_served' => $totalClientsServed,
            'median_wait_minutes' => $medianWait,
            'p90_wait_minutes' => $p90Wait,
            'completion_rate' => $completionRate,
            'active_sessions' => $activeSessions,
            'trend_total' => $prevTotal,
            'trend_median_wait' => $prevMedian,
            'trend_completion_rate' => $prevCompletion,
        ];
    }

    /**
     * Throughput: completed (and optionally cancelled) per day or per hour.
     *
     * @param  array{program_id?: int|null, track_id?: int|null, from?: string, to?: string}  $filters
     * @return array<array{period: string, completed: int, cancelled: int}>
     */
    public function getThroughput(array $filters): array
    {
        $from = isset($filters['from']) ? Carbon::parse($filters['from']) : Carbon::today()->subDays(29);
        $to = isset($filters['to']) ? Carbon::parse($filters['to']) : Carbon::today();
        $groupByHour = $from->isSameDay($to);

        $driver = DB::getDriverName();
        $periodSelect = $groupByHour
            ? ($driver === 'sqlite' ? "strftime('%Y-%m-%d %H:00', queue_sessions.completed_at)" : "DATE_FORMAT(queue_sessions.completed_at, '%Y-%m-%d %H:00')")
            : 'DATE(queue_sessions.completed_at)';

        $rows = Session::query()
            ->from('queue_sessions')
            ->whereNotNull('completed_at')
            ->whereIn('status', ['completed', 'cancelled', 'no_show'])
            ->whereDate('completed_at', '>=', $from)
            ->whereDate('completed_at', '<=', $to);
        if (! empty($filters['program_id'])) {
            $rows->where('program_id', (int) $filters['program_id']);
        }
        if (! empty($filters['track_id'])) {
            $rows->where('track_id', (int) $filters['track_id']);
        }
        $rows = $rows->selectRaw("({$periodSelect}) as period")
            ->selectRaw("SUM(CASE WHEN queue_sessions.status = 'completed' THEN 1 ELSE 0 END) as completed")
            ->selectRaw("SUM(CASE WHEN queue_sessions.status IN ('cancelled','no_show') THEN 1 ELSE 0 END) as cancelled")
            ->groupByRaw("({$periodSelect})")
            ->orderByRaw("({$periodSelect})")
            ->get();

        return $rows->map(fn ($r) => [
            'period' => $r->period,
            'completed' => (int) $r->completed,
            'cancelled' => (int) $r->cancelled,
        ])->all();
    }

    /**
     * Wait time distribution in buckets (minutes): 0-5, 5-10, 10-20, 20-30, 30+.
     *
     * @param  array{program_id?: int|null, track_id?: int|null, from?: string, to?: string}  $filters
     * @return array{buckets: array<array{label: string, min_minutes: int, max_minutes: int|null, count: int}>}
     */
    public function getWaitTimeDistribution(array $filters): array
    {
        $waitMinutes = $this->getWaitTimeMinutesForSessions($filters);
        $buckets = [
            ['label' => '0–5 min', 'min_minutes' => 0, 'max_minutes' => 5, 'count' => 0],
            ['label' => '5–10 min', 'min_minutes' => 5, 'max_minutes' => 10, 'count' => 0],
            ['label' => '10–20 min', 'min_minutes' => 10, 'max_minutes' => 20, 'count' => 0],
            ['label' => '20–30 min', 'min_minutes' => 20, 'max_minutes' => 30, 'count' => 0],
            ['label' => '30+ min', 'min_minutes' => 30, 'max_minutes' => null, 'count' => 0],
        ];
        foreach ($waitMinutes as $m) {
            if ($m < 5) {
                $buckets[0]['count']++;
            } elseif ($m < 10) {
                $buckets[1]['count']++;
            } elseif ($m < 20) {
                $buckets[2]['count']++;
            } elseif ($m < 30) {
                $buckets[3]['count']++;
            } else {
                $buckets[4]['count']++;
            }
        }

        return ['buckets' => $buckets];
    }

    /**
     * Station utilization: busy vs idle minutes per station in the period.
     *
     * @param  array{program_id?: int|null, from?: string, to?: string}  $filters
     * @return array{stations: array<array{station_id: int, name: string, busy_minutes: float, idle_minutes: float, utilization_percent: float}>}
     */
    public function getStationUtilization(array $filters): array
    {
        $from = isset($filters['from']) ? Carbon::parse($filters['from']) : Carbon::today()->subDays(29);
        $to = isset($filters['to']) ? Carbon::parse($filters['to']) : Carbon::today();
        $windowMinutes = max(1, $from->diffInMinutes($to));

        $stationIds = Station::query()->when(! empty($filters['program_id']), fn ($q) => $q->where('program_id', (int) $filters['program_id']))->pluck('id', 'id')->all();
        $names = Station::query()->when(! empty($filters['program_id']), fn ($q) => $q->where('program_id', (int) $filters['program_id']))->get()->keyBy('id');

        if ($stationIds === []) {
            return ['stations' => []];
        }

        $logsQuery = TransactionLog::query()
            ->join('queue_sessions', 'transaction_logs.session_id', '=', 'queue_sessions.id')
            ->whereNotNull('transaction_logs.station_id')
            ->whereIn('transaction_logs.station_id', array_keys($stationIds))
            ->whereBetween('transaction_logs.created_at', [$from, $to]);
        if (! empty($filters['program_id'])) {
            $logsQuery->where('queue_sessions.program_id', (int) $filters['program_id']);
        }
        $logs = $logsQuery
            ->select('transaction_logs.session_id', 'transaction_logs.station_id', 'transaction_logs.action_type', 'transaction_logs.created_at')
            ->orderBy('transaction_logs.session_id')
            ->orderBy('transaction_logs.created_at')
            ->get();

        $sessionStationRanges = [];
        foreach ($logs->groupBy('session_id') as $sessionId => $sessionLogs) {
            $byStation = $sessionLogs->groupBy('station_id');
            foreach ($byStation as $stationId => $stationLogs) {
                $sorted = $stationLogs->sortBy('created_at')->values();
                $first = $sorted->first();
                $last = $sorted->last();
                if ($first && $last && in_array($first->action_type, ['call', 'check_in'], true)) {
                    $end = in_array($last->action_type, ['complete', 'transfer', 'cancel', 'no_show', 'force_complete'], true)
                        ? Carbon::parse($last->created_at)
                        : $to;
                    $start = Carbon::parse($first->created_at);
                    $mins = max(0, $start->diffInMinutes($end));
                    $sessionStationRanges[] = ['station_id' => (int) $stationId, 'minutes' => $mins];
                }
            }
        }

        $busyByStation = [];
        foreach ($sessionStationRanges as $r) {
            $busyByStation[$r['station_id']] = ($busyByStation[$r['station_id']] ?? 0) + $r['minutes'];
        }

        $stations = [];
        foreach ($stationIds as $sid) {
            $busy = $busyByStation[$sid] ?? 0;
            $idle = max(0, $windowMinutes - $busy);
            $util = $windowMinutes > 0 ? round(($busy / $windowMinutes) * 100, 1) : 0.0;
            $stations[] = [
                'station_id' => $sid,
                'name' => $names->get($sid)?->name ?? 'Station '.$sid,
                'busy_minutes' => round($busy, 1),
                'idle_minutes' => round($idle, 1),
                'utilization_percent' => $util,
            ];
        }

        return ['stations' => $stations];
    }

    /**
     * Track performance: avg total time, median wait, completion rate per track.
     *
     * @param  array{program_id?: int|null, from?: string, to?: string}  $filters
     * @return array{tracks: array<array{track_id: int, track_name: string, avg_total_minutes: float, median_wait_minutes: float|null, completion_rate: float}>}
     */
    public function getTrackPerformance(array $filters): array
    {
        $from = isset($filters['from']) ? $filters['from'] : Carbon::today()->subDays(29)->toDateString();
        $to = isset($filters['to']) ? $filters['to'] : Carbon::today()->toDateString();

        $sessions = Session::query()
            ->with('serviceTrack')
            ->whereNotNull('completed_at')
            ->whereDate('started_at', '>=', $from)
            ->whereDate('started_at', '<=', $to);
        if (! empty($filters['program_id'])) {
            $sessions->where('program_id', (int) $filters['program_id']);
        }
        $sessions = $sessions->get();

        $byTrack = $sessions->groupBy('track_id');
        $waitBySessionId = $this->getWaitMinutesBySessionId($filters);

        $tracks = [];
        foreach ($byTrack as $trackId => $group) {
            $track = $group->first()?->serviceTrack;
            $totalStarted = $group->count();
            $completed = $group->where('status', 'completed')->count();
            $completionRate = $totalStarted > 0 ? round(($completed / $totalStarted) * 100, 1) : 0.0;
            $totalMinutes = $group->filter(fn ($s) => $s->completed_at)->map(fn ($s) => Carbon::parse($s->started_at)->diffInMinutes(Carbon::parse($s->completed_at)))->values()->all();
            $avgTotal = count($totalMinutes) > 0 ? array_sum($totalMinutes) / count($totalMinutes) : 0.0;
            $waits = [];
            foreach ($group as $s) {
                if (isset($waitBySessionId[$s->id])) {
                    $waits[] = $waitBySessionId[$s->id];
                }
            }
            $medianWait = $this->percentile($waits, 50);

            $tracks[] = [
                'track_id' => (int) $trackId,
                'track_name' => $track?->name ?? 'Track '.$trackId,
                'avg_total_minutes' => round($avgTotal, 1),
                'median_wait_minutes' => $medianWait,
                'completion_rate' => $completionRate,
            ];
        }

        return ['tracks' => $tracks];
    }

    /**
     * Busiest hours heatmap: count of sessions started by day-of-week and hour.
     *
     * @param  array{program_id?: int|null, track_id?: int|null, from?: string, to?: string}  $filters
     * @return array{heatmap: array<array{day_of_week: int, hour: int, count: int}>, days: array<string>, hours: array<int>}
     */
    public function getBusiestHours(array $filters): array
    {
        $from = isset($filters['from']) ? Carbon::parse($filters['from']) : Carbon::today()->subDays(29);
        $to = isset($filters['to']) ? Carbon::parse($filters['to']) : Carbon::today();

        $baseQuery = Session::query()
            ->whereDate('started_at', '>=', $from)
            ->whereDate('started_at', '<=', $to);
        if (! empty($filters['program_id'])) {
            $baseQuery->where('program_id', (int) $filters['program_id']);
        }
        if (! empty($filters['track_id'])) {
            $baseQuery->where('track_id', (int) $filters['track_id']);
        }

        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            $rows = (clone $baseQuery)
                ->selectRaw("CAST(strftime('%w', queue_sessions.started_at) AS INT) as day_of_week")
                ->selectRaw("CAST(strftime('%H', queue_sessions.started_at) AS INT) as hour")
                ->selectRaw('COUNT(*) as count')
                ->groupBy('day_of_week', 'hour')
                ->get();
        } else {
            $rows = (clone $baseQuery)
                ->selectRaw('DAYOFWEEK(queue_sessions.started_at) as day_of_week')
                ->selectRaw('HOUR(queue_sessions.started_at) as hour')
                ->selectRaw('COUNT(*) as count')
                ->groupBy('day_of_week', 'hour')
                ->get();
        }

        $heatmap = $rows->map(fn ($r) => [
            'day_of_week' => (int) $r->day_of_week,
            'hour' => (int) $r->hour,
            'count' => (int) $r->count,
        ])->all();

        $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $hours = range(0, 23);

        return ['heatmap' => $heatmap, 'days' => $days, 'hours' => $hours];
    }

    /**
     * Drop-off funnel: counts at each step (ticket_issued → called → checked_in → ... → completed).
     *
     * @param  array{program_id?: int|null, track_id?: int|null, from?: string, to?: string}  $filters
     * @return array{steps: array<array{step: string, count: int}>}
     */
    public function getDropOffFunnel(array $filters): array
    {
        $from = isset($filters['from']) ? $filters['from'] : Carbon::today()->subDays(29)->toDateString();
        $to = isset($filters['to']) ? $filters['to'] : Carbon::today()->toDateString();

        $sessionIds = Session::query()
            ->whereDate('started_at', '>=', $from)
            ->whereDate('started_at', '<=', $to)
            ->when(! empty($filters['program_id']), fn ($q) => $q->where('program_id', (int) $filters['program_id']))
            ->when(! empty($filters['track_id']), fn ($q) => $q->where('track_id', (int) $filters['track_id']))
            ->pluck('id');

        $issued = $sessionIds->count();
        $called = TransactionLog::query()->whereIn('session_id', $sessionIds)->where('action_type', 'call')->distinct('session_id')->count('session_id');
        $checkedIn = TransactionLog::query()->whereIn('session_id', $sessionIds)->where('action_type', 'check_in')->distinct('session_id')->count('session_id');
        $completed = Session::query()->whereIn('id', $sessionIds)->where('status', 'completed')->count();
        $cancelled = Session::query()->whereIn('id', $sessionIds)->where('status', 'cancelled')->count();
        $noShow = Session::query()->whereIn('id', $sessionIds)->where('status', 'no_show')->count();

        return [
            'steps' => [
                ['step' => 'Ticket Issued', 'count' => $issued],
                ['step' => 'Called', 'count' => $called],
                ['step' => 'Checked In', 'count' => $checkedIn],
                ['step' => 'Completed', 'count' => $completed],
                ['step' => 'Cancelled', 'count' => $cancelled],
                ['step' => 'No-show', 'count' => $noShow],
            ],
        ];
    }

    /**
     * Token and TTS health: counts by status and tts_status.
     *
     * @return array{by_status: array<array{status: string, count: int}>, by_tts_status: array<array{tts_status: string, count: int}>}
     */
    public function getTokenTtsHealth(): array
    {
        $byStatus = Token::query()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->get()
            ->map(fn ($r) => ['status' => $r->status, 'count' => (int) $r->count])
            ->all();

        $byTtsStatus = [];
        if (Schema::hasColumn((new Token)->getTable(), 'tts_status')) {
            $byTts = Token::query()
                ->selectRaw("COALESCE(tts_status, 'none') as tts_status, count(*) as count")
                ->groupByRaw("COALESCE(tts_status, 'none')")
                ->get();
            $byTtsStatus = $byTts->map(fn ($r) => ['tts_status' => $r->tts_status ?? 'none', 'count' => (int) $r->count])->all();
        }

        return ['by_status' => $byStatus, 'by_tts_status' => $byTtsStatus];
    }

    /**
     * Get wait times (minutes) for sessions in filter range that have at least one 'call' log.
     *
     * @return array<int, float>
     */
    private function getWaitTimeMinutesForSessions(array $filters): array
    {
        $bySession = $this->getWaitMinutesBySessionId($filters);

        return array_values($bySession);
    }

    /**
     * Get wait time in minutes keyed by session_id (sessions that have at least one 'call' log).
     *
     * @return array<int, float>
     */
    private function getWaitMinutesBySessionId(array $filters): array
    {
        $from = $filters['from'] ?? Carbon::today()->subDays(29)->toDateString();
        $to = $filters['to'] ?? Carbon::today()->toDateString();

        $firstCalls = TransactionLog::query()
            ->join('queue_sessions', 'transaction_logs.session_id', '=', 'queue_sessions.id')
            ->where('transaction_logs.action_type', 'call')
            ->whereDate('queue_sessions.started_at', '>=', $from)
            ->whereDate('queue_sessions.started_at', '<=', $to)
            ->when(! empty($filters['program_id']), fn ($q) => $q->where('queue_sessions.program_id', (int) $filters['program_id']))
            ->when(! empty($filters['track_id']), fn ($q) => $q->where('queue_sessions.track_id', (int) $filters['track_id']))
            ->select('transaction_logs.session_id', 'queue_sessions.started_at', DB::raw('MIN(transaction_logs.created_at) as first_call_at'))
            ->groupBy('transaction_logs.session_id', 'queue_sessions.started_at')
            ->get();

        $bySession = [];
        foreach ($firstCalls as $row) {
            $started = Carbon::parse($row->started_at);
            $firstCall = Carbon::parse($row->first_call_at);
            $bySession[(int) $row->session_id] = max(0, $started->diffInMinutes($firstCall, false));
        }

        return $bySession;
    }

    private function percentile(array $sortedValues, float $p): ?float
    {
        if ($sortedValues === []) {
            return null;
        }
        sort($sortedValues);
        $k = ($p / 100) * (count($sortedValues) - 1);
        $f = floor($k);
        $c = ceil($k);
        if ($f === $c) {
            return (float) $sortedValues[(int) $k];
        }

        return (float) ($sortedValues[(int) $f] + ($k - $f) * ($sortedValues[(int) $c] - $sortedValues[(int) $f]));
    }
}
