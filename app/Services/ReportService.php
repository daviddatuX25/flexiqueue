<?php

namespace App\Services;

use App\Models\ProgramAuditLog;
use App\Models\TransactionLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as LengthAwarePaginatorConcrete;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Per 08-API-SPEC-PHASE1 §5.8: Audit log query and CSV export.
 * Per 05-SECURITY-CONTROLS §6: Admin can view full transaction log.
 * Includes program session start/stop (program_audit_log) and filters: program_session, staff.
 */
class ReportService
{
    private const PER_PAGE = 50;

    /**
     * List program sessions (session_start + matching session_stop) for filter dropdown.
     *
     * @param  array{program_id?: int, from?: string, to?: string}  $filters
     * @return array<int, array{id: int, program_id: int, program_name: string, started_at: string, ended_at: string|null, started_by: string}>
     */
    public function getProgramSessions(array $filters): array
    {
        $starts = ProgramAuditLog::query()
            ->where('action', 'session_start')
            ->with(['program:id,name', 'staffUser:id,name'])
            ->orderByDesc('created_at');

        if (! empty($filters['program_id'])) {
            $starts->where('program_id', (int) $filters['program_id']);
        }
        if (! empty($filters['from'])) {
            $starts->whereDate('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $starts->whereDate('created_at', '<=', $filters['to']);
        }

        $sessions = [];
        foreach ($starts->get() as $start) {
            $endedAt = ProgramAuditLog::query()
                ->where('program_id', $start->program_id)
                ->where('action', 'session_stop')
                ->where('created_at', '>', $start->created_at)
                ->orderBy('created_at')
                ->value('created_at');

            $sessions[] = [
                'id' => $start->id,
                'program_id' => $start->program_id,
                'program_name' => $start->program?->name ?? '—',
                'started_at' => $start->created_at->toIso8601String(),
                'ended_at' => $endedAt ? Carbon::parse($endedAt)->toIso8601String() : null,
                'started_by' => $start->staffUser?->name ?? '—',
            ];
        }

        return $sessions;
    }

    /**
     * Get program session by id (session_start log id). Returns started_at, ended_at, program_id for filtering.
     *
     * @return array{program_id: int, started_at: string, ended_at: string|null}|null
     */
    public function getProgramSessionRange(int $programSessionId): ?array
    {
        $start = ProgramAuditLog::query()
            ->where('id', $programSessionId)
            ->where('action', 'session_start')
            ->first();

        if (! $start) {
            return null;
        }

        $endedAt = ProgramAuditLog::query()
            ->where('program_id', $start->program_id)
            ->where('action', 'session_stop')
            ->where('created_at', '>', $start->created_at)
            ->orderBy('created_at')
            ->value('created_at');

        return [
            'program_id' => $start->program_id,
            'started_at' => $start->created_at->toIso8601String(),
            'ended_at' => $endedAt ? Carbon::parse($endedAt)->toIso8601String() : null,
        ];
    }

    /**
     * Get paginated audit log entries (transaction_logs + program_audit_log merged, sorted by created_at desc).
     *
     * @param  array{program_id?: int, from?: string, to?: string, action_type?: string, station_id?: int, staff_user_id?: int, program_session_id?: int, page?: int, per_page?: int}  $filters
     */
    public function getAuditLog(array $filters): LengthAwarePaginator
    {
        $programSessionRange = null;
        if (! empty($filters['program_session_id'])) {
            $programSessionRange = $this->getProgramSessionRange((int) $filters['program_session_id']);
            if ($programSessionRange) {
                $filters['program_id'] = $programSessionRange['program_id'];
                $filters['from'] = Carbon::parse($programSessionRange['started_at'])->toDateString();
                $filters['to'] = $programSessionRange['ended_at']
                    ? Carbon::parse($programSessionRange['ended_at'])->toDateString()
                    : null;
                $filters['_range_start'] = $programSessionRange['started_at'];
                $filters['_range_end'] = $programSessionRange['ended_at'];
            }
        }

        $perPage = (int) ($filters['per_page'] ?? self::PER_PAGE);
        $perPage = max(10, min(100, $perPage));
        $page = max(1, (int) ($filters['page'] ?? 1));

        $transactionLogs = $this->getTransactionLogsForAudit($filters);
        $programLogs = $this->getProgramAuditLogsForAudit($filters);

        $merged = $transactionLogs->concat($programLogs)->sortByDesc('created_at')->values();
        $total = $merged->count();
        $slice = $merged->slice(($page - 1) * $perPage, $perPage)->values()->all();

        return new LengthAwarePaginatorConcrete($slice, $total, $perPage, $page, ['path' => '']);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array{id: int|string, source: string, session_alias: string, action_type: string, station: string, staff: string, remarks: string|null, created_at: string}>
     */
    private function getTransactionLogsForAudit(array $filters): Collection
    {
        $query = TransactionLog::query()
            ->with(['session:id,alias,program_id', 'station:id,name', 'previousStation:id,name', 'nextStation:id,name', 'staffUser:id,name'])
            ->join('queue_sessions', 'transaction_logs.session_id', '=', 'queue_sessions.id')
            ->select('transaction_logs.*');

        if (! empty($filters['program_id'])) {
            $query->where('queue_sessions.program_id', (int) $filters['program_id']);
        }
        if (! empty($filters['from'])) {
            $query->whereDate('transaction_logs.created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('transaction_logs.created_at', '<=', $filters['to']);
        }
        if (isset($filters['_range_start'])) {
            $query->where('transaction_logs.created_at', '>=', $filters['_range_start']);
        }
        if (! empty($filters['_range_end'])) {
            $query->where('transaction_logs.created_at', '<=', $filters['_range_end']);
        }
        if (! empty($filters['action_type'])) {
            $query->where('transaction_logs.action_type', $filters['action_type']);
        }
        if (isset($filters['station_id']) && $filters['station_id'] !== '' && $filters['station_id'] !== null) {
            $sid = (int) $filters['station_id'];
            $query->where(function ($q) use ($sid) {
                $q->where('transaction_logs.station_id', $sid)
                    ->orWhere('transaction_logs.previous_station_id', $sid)
                    ->orWhere('transaction_logs.next_station_id', $sid);
            });
        }
        if (! empty($filters['staff_user_id'])) {
            $query->where('transaction_logs.staff_user_id', (int) $filters['staff_user_id']);
        }

        $query->orderByDesc('transaction_logs.created_at');

        return $query->get()->map(function (TransactionLog $log) {
            return [
                'id' => $log->id,
                'source' => 'transaction',
                'session_alias' => $log->session?->alias ?? '',
                'action_type' => $log->action_type,
                'station' => $this->stationLabel($log),
                'staff' => $log->staffUser?->name ?? '',
                'remarks' => $log->remarks,
                'created_at' => $log->created_at?->toIso8601String() ?? '',
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array{id: string, source: string, session_alias: string, action_type: string, station: string, staff: string, remarks: string|null, created_at: string}>
     */
    private function getProgramAuditLogsForAudit(array $filters): Collection
    {
        $query = ProgramAuditLog::query()
            ->with(['staffUser:id,name']);

        if (! empty($filters['program_id'])) {
            $query->where('program_id', (int) $filters['program_id']);
        }
        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }
        if (isset($filters['_range_start'])) {
            $query->where('created_at', '>=', $filters['_range_start']);
        }
        if (! empty($filters['_range_end'])) {
            $query->where('created_at', '<=', $filters['_range_end']);
        }
        if (! empty($filters['action_type'])) {
            $query->where('action', $filters['action_type']);
        }
        if (! empty($filters['staff_user_id'])) {
            $query->where('staff_user_id', (int) $filters['staff_user_id']);
        }

        $query->orderByDesc('created_at');

        return $query->get()->map(function (ProgramAuditLog $log) {
            return [
                'id' => 'pal-'.$log->id,
                'source' => 'program_session',
                'session_alias' => '—',
                'action_type' => $log->action,
                'station' => '—',
                'staff' => $log->staffUser?->name ?? '',
                'remarks' => null,
                'created_at' => $log->created_at?->toIso8601String() ?? '',
            ];
        });
    }

    /**
     * Transform a TransactionLog for API response (per 08-API-SPEC §5.8).
     */
    public function auditLogResource(TransactionLog $log): array
    {
        $station = $this->stationLabel($log);

        return [
            'id' => $log->id,
            'source' => 'transaction',
            'session_alias' => $log->session?->alias ?? '',
            'action_type' => $log->action_type,
            'station' => $station,
            'staff' => $log->staffUser?->name ?? '',
            'remarks' => $log->remarks,
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }

    /**
     * Stream CSV export of audit log. Same filters as getAuditLog (includes program_session, staff), no pagination.
     * Includes both transaction_logs and program_audit_log rows.
     *
     * @param  array{program_id?: int, from?: string, to?: string, action_type?: string, station_id?: int, staff_user_id?: int, program_session_id?: int}  $filters
     */
    public function streamAuditCsv(array $filters): StreamedResponse
    {
        $programSessionRange = null;
        if (! empty($filters['program_session_id'])) {
            $programSessionRange = $this->getProgramSessionRange((int) $filters['program_session_id']);
            if ($programSessionRange) {
                $filters['program_id'] = $programSessionRange['program_id'];
                $filters['from'] = Carbon::parse($programSessionRange['started_at'])->toDateString();
                $filters['to'] = $programSessionRange['ended_at']
                    ? Carbon::parse($programSessionRange['ended_at'])->toDateString()
                    : null;
                $filters['_range_start'] = $programSessionRange['started_at'];
                $filters['_range_end'] = $programSessionRange['ended_at'];
            }
        }

        $transactionLogs = $this->getTransactionLogsForAudit($filters);
        $programLogs = $this->getProgramAuditLogsForAudit($filters);
        $merged = $transactionLogs->concat($programLogs)->sortBy('created_at')->values();

        $filename = 'flexiqueue-audit-'.Carbon::now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($merged) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Time', 'Source', 'Session', 'Action', 'Station', 'Staff', 'Remarks']);
            foreach ($merged as $row) {
                fputcsv($handle, [
                    $row['created_at'] ?? '',
                    $row['source'] ?? 'transaction',
                    $row['session_alias'] ?? '',
                    $row['action_type'] ?? '',
                    $row['station'] ?? '',
                    $row['staff'] ?? '',
                    $row['remarks'] ?? '',
                ]);
            }
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private function stationLabel(TransactionLog $log): string
    {
        if ($log->action_type === 'bind' && $log->station_id === null) {
            return 'Triage';
        }
        if ($log->station?->name) {
            return $log->station->name;
        }
        if ($log->nextStation?->name) {
            return $log->nextStation->name;
        }
        if ($log->previousStation?->name) {
            return $log->previousStation->name;
        }

        return '—';
    }
}
