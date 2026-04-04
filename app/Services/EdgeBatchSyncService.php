<?php

namespace App\Services;

use App\Models\EdgeDeviceState;
use Illuminate\Support\Facades\DB;

class EdgeBatchSyncService
{
    /**
     * Collect all records created since last_synced_at from edge-local tables.
     * Returns the batch payload ready to send to central.
     */
    public function collectUnsyncedData(): array
    {
        $state = EdgeDeviceState::current();
        $since = $state->last_synced_at;
        $programId = $state->active_program_id;

        $sessions = $this->collectSessions($since);
        $transactionLogs = $this->collectTransactionLogs($since);
        $clients = $this->collectClients($since);
        $identityRegistrations = $this->collectIdentityRegistrations($since);
        $programAuditLog = $this->collectProgramAuditLog($since);
        $staffActivityLog = $this->collectStaffActivityLog($since);
        $tokenUpdates = $this->collectTokenUpdates($sessions);

        $totalServed = collect($sessions)->where('status', 'completed')->count();
        $totalCancelled = collect($sessions)->where('status', 'cancelled')->count();

        return [
            'session_summary' => [
                'program_id' => $programId,
                'started_at' => collect($sessions)->min('created_at') ?? now()->toIso8601String(),
                'ended_at' => collect($sessions)->max('updated_at') ?? now()->toIso8601String(),
                'tokens_served' => $totalServed,
                'tokens_cancelled' => $totalCancelled,
            ],
            'queue_sessions' => $sessions,
            'transaction_logs' => $transactionLogs,
            'clients' => $clients,
            'identity_registrations' => $identityRegistrations,
            'program_audit_log' => $programAuditLog,
            'staff_activity_log' => $staffActivityLog,
            'token_updates' => $tokenUpdates,
        ];
    }

    private function collectSessions(?string $since): array
    {
        $query = DB::table('queue_sessions');
        if ($since) {
            $query->where('created_at', '>', $since);
        }
        return $query->get()->map(fn ($row) => (array) $row)->values()->all();
    }

    private function collectTransactionLogs(?string $since): array
    {
        $query = DB::table('transaction_logs');
        if ($since) {
            $query->where('created_at', '>', $since);
        }
        return $query->get()->map(fn ($row) => (array) $row)->values()->all();
    }

    private function collectClients(?string $since): array
    {
        $query = DB::table('clients');
        if ($since) {
            $query->where('created_at', '>', $since);
        }
        return $query->get()->map(fn ($row) => (array) $row)->values()->all();
    }

    private function collectIdentityRegistrations(?string $since): array
    {
        $query = DB::table('identity_registrations');
        if ($since) {
            $query->where('created_at', '>', $since);
        }
        return $query->get()->map(fn ($row) => (array) $row)->values()->all();
    }

    private function collectProgramAuditLog(?string $since): array
    {
        $query = DB::table('program_audit_log');
        if ($since) {
            $query->where('created_at', '>', $since);
        }
        return $query->get()->map(fn ($row) => (array) $row)->values()->all();
    }

    private function collectStaffActivityLog(?string $since): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('staff_activity_log')) {
            return [];
        }
        $query = DB::table('staff_activity_log');
        if ($since) {
            $query->where('created_at', '>', $since);
        }
        return $query->get()->map(fn ($row) => (array) $row)->values()->all();
    }

    private function collectTokenUpdates(array $sessions): array
    {
        $tokenIds = collect($sessions)
            ->pluck('token_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($tokenIds)) {
            return [];
        }

        return DB::table('tokens')
            ->whereIn('id', $tokenIds)
            ->get(['id', 'status'])
            ->map(fn ($row) => ['id' => $row->id, 'status' => $row->status])
            ->values()
            ->all();
    }
}
