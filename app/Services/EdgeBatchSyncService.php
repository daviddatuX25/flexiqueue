<?php

namespace App\Services;

use App\Models\EdgeDeviceState;
use App\Models\EdgeSyncReceipt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

    /**
     * Check if there is any data that needs syncing (lightweight EXISTS queries).
     */
    public function hasUnsyncedData(): bool
    {
        $since = EdgeDeviceState::current()->last_synced_at;

        $check = fn (string $table) => $since
            ? DB::table($table)->where('created_at', '>', $since)->exists()
            : DB::table($table)->exists();

        return $check('queue_sessions')
            || $check('transaction_logs')
            || $check('clients')
            || $check('identity_registrations')
            || $check('program_audit_log');
    }

    /**
     * Collect unsynced data, push to central, store receipt.
     * Returns true on success (or no-op), false on failure.
     * Uses a cache lock to prevent concurrent sync attempts.
     */
    public function pushToCentral(): bool
    {
        $lock = Cache::lock('edge.batch_sync', 60);

        if (! $lock->get()) {
            Log::info('EdgeBatchSyncService: sync already in progress, skipping.');
            return false;
        }

        try {
            return $this->doPush();
        } finally {
            $lock->release();
        }
    }

    private function doPush(): bool
    {
        $state = EdgeDeviceState::current();
        $centralUrl = rtrim($state->central_url, '/');
        $deviceToken = $state->device_token;

        $payload = $this->collectUnsyncedData();

        // Nothing to sync
        $hasData = ! empty($payload['queue_sessions'])
            || ! empty($payload['transaction_logs'])
            || ! empty($payload['clients'])
            || ! empty($payload['identity_registrations'])
            || ! empty($payload['program_audit_log'])
            || ! empty($payload['staff_activity_log']);

        if (! $hasData) {
            return true;
        }

        $batchId = (string) Str::uuid();
        $receipt = EdgeSyncReceipt::create([
            'batch_id' => $batchId,
            'status' => 'pending',
            'payload_summary' => [
                'queue_sessions' => count($payload['queue_sessions']),
                'transaction_logs' => count($payload['transaction_logs']),
                'clients' => count($payload['clients']),
                'identity_registrations' => count($payload['identity_registrations']),
                'program_audit_log' => count($payload['program_audit_log']),
                'staff_activity_log' => count($payload['staff_activity_log']),
                'token_updates' => count($payload['token_updates']),
            ],
            'started_at' => now(),
        ]);

        try {
            $response = Http::timeout(30)
                ->withToken($deviceToken)
                ->post("{$centralUrl}/api/edge/sync", $payload);

            if ($response->successful()) {
                $responseData = $response->json();
                $receipt->markComplete($responseData);
                $state->update(['last_synced_at' => now()]);

                Log::info('EdgeBatchSyncService: batch sync complete', [
                    'batch_id' => $batchId,
                    'records' => $responseData['records_received'] ?? [],
                ]);

                return true;
            }

            Log::warning('EdgeBatchSyncService: central returned ' . $response->status(), [
                'batch_id' => $batchId,
            ]);

            $receipt->markFailed();
            return false;
        } catch (\Throwable $e) {
            Log::warning('EdgeBatchSyncService: HTTP failed', [
                'batch_id' => $batchId,
                'error' => $e->getMessage(),
            ]);

            $receipt->markFailed();
            return false;
        }
    }
}
