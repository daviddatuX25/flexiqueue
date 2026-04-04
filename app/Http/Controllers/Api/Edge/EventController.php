<?php

namespace App\Http\Controllers\Api\Edge;

use App\Http\Controllers\Controller;
use App\Models\Session;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class EventController extends Controller
{
    private const VALID_EVENT_TYPES = [
        'transaction_log',
    ];

    public function __invoke(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'event_type' => ['required', 'string', 'in:' . implode(',', self::VALID_EVENT_TYPES)],
            'payload' => ['required', 'array'],
            'payload.session_id' => ['required', 'integer'],
            'payload.action_type' => ['required', 'string'],
            'payload.created_at' => ['required', 'string'],
            'session_state' => ['nullable', 'array'],
            'session_state.id' => ['required_with:session_state', 'integer'],
            'session_state.status' => ['required_with:session_state', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $device = $request->attributes->get('edge_device');
        $eventType = $request->input('event_type');
        $payload = $request->input('payload');
        $sessionState = $request->input('session_state');

        try {
            DB::transaction(function () use ($eventType, $payload, $sessionState, $device) {
                if ($eventType === 'transaction_log') {
                    $this->processTransactionLog($payload, $device);
                }

                if ($sessionState) {
                    $this->updateSessionState($sessionState);
                }
            });

            Log::info('Edge event received', [
                'device_id' => $device->id,
                'event_type' => $eventType,
                'action_type' => $payload['action_type'] ?? null,
            ]);

            return response()->json(['status' => 'ok']);
        } catch (\Throwable $e) {
            Log::error('Edge event processing failed', [
                'device_id' => $device->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Processing failed',
            ], 500);
        }
    }

    private function processTransactionLog(array $payload, $device): void
    {
        // Deduplicate: skip if this transaction_log ID already exists
        $existingLog = DB::table('transaction_logs')->find($payload['id'] ?? null);
        if ($existingLog) {
            return; // idempotent — already received
        }

        // Insert the transaction log on central (bypass the model's
        // `created` hook since we're on central, not edge)
        DB::table('transaction_logs')->insert([
            'id' => $payload['id'] ?? null,
            'session_id' => $payload['session_id'],
            'station_id' => $payload['station_id'] ?? null,
            'staff_user_id' => $payload['staff_user_id'] ?? null,
            'action_type' => $payload['action_type'],
            'previous_station_id' => $payload['previous_station_id'] ?? null,
            'next_station_id' => $payload['next_station_id'] ?? null,
            'remarks' => $payload['remarks'] ?? null,
            'metadata' => isset($payload['metadata']) ? json_encode($payload['metadata']) : null,
            'created_at' => $payload['created_at'],
        ]);
    }

    private function updateSessionState(array $sessionState): void
    {
        $session = Session::find($sessionState['id']);
        if (! $session) {
            return; // Session doesn't exist on central yet — skip state update
        }

        $updateData = array_filter([
            'status' => $sessionState['status'] ?? null,
            'current_station_id' => $sessionState['current_station_id'] ?? null,
        ], fn ($v) => $v !== null);

        if (! empty($updateData)) {
            $session->update($updateData);
        }
    }
}
