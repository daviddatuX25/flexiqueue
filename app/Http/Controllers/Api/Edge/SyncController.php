<?php

namespace App\Http\Controllers\Api\Edge;

use App\Http\Controllers\Controller;
use App\Models\Session;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SyncController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'session_summary' => ['required', 'array'],
            'session_summary.program_id' => ['required', 'integer'],
            'session_summary.started_at' => ['required', 'string'],
            'session_summary.ended_at' => ['required', 'string'],
            'queue_sessions' => ['present', 'array'],
            'queue_sessions.*.id' => ['required', 'integer'],
            'queue_sessions.*.token_id' => ['required', 'integer'],
            'queue_sessions.*.program_id' => ['required', 'integer'],
            'queue_sessions.*.status' => ['required', 'string'],
            'transaction_logs' => ['present', 'array'],
            'transaction_logs.*.id' => ['required', 'integer'],
            'transaction_logs.*.session_id' => ['required', 'integer'],
            'transaction_logs.*.action_type' => ['required', 'string'],
            'transaction_logs.*.created_at' => ['required', 'string'],
            'clients' => ['present', 'array'],
            'identity_registrations' => ['present', 'array'],
            'program_audit_log' => ['present', 'array'],
            'staff_activity_log' => ['present', 'array'],
            'token_updates' => ['present', 'array'],
            'token_updates.*.id' => ['required', 'integer'],
            'token_updates.*.status' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $device = $request->attributes->get('edge_device');

        $counts = [
            'queue_sessions' => 0,
            'transaction_logs' => 0,
            'clients' => 0,
            'identity_registrations' => 0,
        ];

        try {
            DB::transaction(function () use ($request, $device, &$counts) {
                $counts['queue_sessions'] = $this->upsertQueueSessions($request->input('queue_sessions', []));
                $counts['transaction_logs'] = $this->insertTransactionLogs($request->input('transaction_logs', []));
                $counts['clients'] = $this->upsertClients($request->input('clients', []));
                $counts['identity_registrations'] = $this->upsertIdentityRegistrations($request->input('identity_registrations', []));
                $this->insertProgramAuditLog($request->input('program_audit_log', []));
                $this->insertStaffActivityLog($request->input('staff_activity_log', []));
                $this->updateTokenStatuses($request->input('token_updates', []));

                $device->update(['last_synced_at' => now()]);
            });

            Log::info('Edge batch sync complete', [
                'device_id' => $device->id,
                'program_id' => $request->input('session_summary.program_id'),
                'counts' => $counts,
            ]);

            return response()->json([
                'status' => 'complete',
                'synced_at' => now()->toIso8601String(),
                'records_received' => $counts,
                'conflicts' => [],
            ]);
        } catch (\Throwable $e) {
            Log::error('Edge batch sync failed', [
                'device_id' => $device->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Batch sync processing failed',
            ], 500);
        }
    }

    private function upsertQueueSessions(array $sessions): int
    {
        $count = 0;
        foreach ($sessions as $row) {
            $existing = Session::find($row['id']);
            if ($existing) {
                $existing->update(array_filter([
                    'status' => $row['status'] ?? null,
                    'current_station_id' => $row['current_station_id'] ?? null,
                    'updated_at' => $row['updated_at'] ?? now(),
                ], fn ($v) => $v !== null));
            } else {
                DB::table('queue_sessions')->insert([
                    'id' => $row['id'],
                    'token_id' => $row['token_id'],
                    'program_id' => $row['program_id'],
                    'track_id' => $row['track_id'] ?? null,
                    'alias' => $row['alias'] ?? null,
                    'client_category' => $row['client_category'] ?? null,
                    'client_id' => $row['client_id'] ?? null,
                    'identity_registration_id' => $row['identity_registration_id'] ?? null,
                    'current_station_id' => $row['current_station_id'] ?? null,
                    'holding_station_id' => $row['holding_station_id'] ?? null,
                    'current_step_order' => $row['current_step_order'] ?? null,
                    'override_steps' => isset($row['override_steps']) ? (is_string($row['override_steps']) ? $row['override_steps'] : json_encode($row['override_steps'])) : null,
                    'station_queue_position' => $row['station_queue_position'] ?? null,
                    'priority_lane_override' => $row['priority_lane_override'] ?? null,
                    'is_on_hold' => $row['is_on_hold'] ?? false,
                    'held_at' => $row['held_at'] ?? null,
                    'held_order' => $row['held_order'] ?? null,
                    'no_show_attempts' => $row['no_show_attempts'] ?? 0,
                    'status' => $row['status'],
                    'started_at' => $row['started_at'] ?? now(),
                    'queued_at_station' => $row['queued_at_station'] ?? null,
                    'completed_at' => $row['completed_at'] ?? null,
                    'created_at' => $row['created_at'] ?? now(),
                    'updated_at' => $row['updated_at'] ?? now(),
                ]);
            }
            $count++;
        }
        return $count;
    }

    private function insertTransactionLogs(array $logs): int
    {
        $count = 0;
        foreach ($logs as $row) {
            $exists = DB::table('transaction_logs')->where('id', $row['id'])->exists();
            if ($exists) {
                continue; // deduplicate — already received
            }

            DB::table('transaction_logs')->insert([
                'id' => $row['id'],
                'session_id' => $row['session_id'],
                'station_id' => $row['station_id'] ?? null,
                'staff_user_id' => $row['staff_user_id'] ?? null,
                'action_type' => $row['action_type'],
                'previous_station_id' => $row['previous_station_id'] ?? null,
                'next_station_id' => $row['next_station_id'] ?? null,
                'remarks' => $row['remarks'] ?? null,
                'metadata' => isset($row['metadata']) ? json_encode($row['metadata']) : null,
                'created_at' => $row['created_at'],
            ]);
            $count++;
        }
        return $count;
    }

    private function upsertClients(array $clients): int
    {
        $count = 0;
        foreach ($clients as $row) {
            DB::table('clients')->updateOrInsert(
                ['id' => $row['id']],
                [
                    'site_id' => $row['site_id'] ?? null,
                    'first_name' => $row['first_name'] ?? null,
                    'middle_name' => $row['middle_name'] ?? null,
                    'last_name' => $row['last_name'] ?? null,
                    'birth_date' => $row['birth_date'] ?? null,
                    'identity_hash' => $row['identity_hash'] ?? null,
                    'updated_at' => $row['updated_at'] ?? now(),
                ]
            );
            $count++;
        }
        return $count;
    }

    private function upsertIdentityRegistrations(array $registrations): int
    {
        $count = 0;
        foreach ($registrations as $row) {
            DB::table('identity_registrations')->updateOrInsert(
                ['id' => $row['id']],
                [
                    'program_id' => $row['program_id'] ?? null,
                    'request_type' => $row['request_type'] ?? null,
                    'session_id' => $row['session_id'] ?? null,
                    'token_id' => $row['token_id'] ?? null,
                    'first_name' => $row['first_name'] ?? null,
                    'middle_name' => $row['middle_name'] ?? null,
                    'last_name' => $row['last_name'] ?? null,
                    'birth_date' => $row['birth_date'] ?? null,
                    'client_category' => $row['client_category'] ?? null,
                    'status' => $row['status'] ?? 'pending',
                    'client_id' => $row['client_id'] ?? null,
                    'updated_at' => $row['updated_at'] ?? now(),
                ]
            );
            $count++;
        }
        return $count;
    }

    private function insertProgramAuditLog(array $logs): void
    {
        foreach ($logs as $row) {
            $exists = DB::table('program_audit_log')->where('id', $row['id'])->exists();
            if ($exists) {
                continue;
            }

            DB::table('program_audit_log')->insert([
                'id' => $row['id'],
                'program_id' => $row['program_id'],
                'staff_user_id' => $row['staff_user_id'] ?? null,
                'action' => $row['action'],
                'created_at' => $row['created_at'],
            ]);
        }
    }

    private function insertStaffActivityLog(array $logs): void
    {
        foreach ($logs as $row) {
            $exists = DB::table('staff_activity_log')->where('id', $row['id'])->exists();
            if ($exists) {
                continue;
            }

            DB::table('staff_activity_log')->insert([
                'id' => $row['id'],
                'user_id' => $row['user_id'],
                'action_type' => $row['action_type'],
                'old_value' => $row['old_value'] ?? null,
                'new_value' => $row['new_value'] ?? null,
                'created_at' => $row['created_at'],
            ]);
        }
    }

    private function updateTokenStatuses(array $tokenUpdates): void
    {
        foreach ($tokenUpdates as $row) {
            DB::table('tokens')
                ->where('id', $row['id'])
                ->update(['status' => $row['status']]);
        }
    }
}
