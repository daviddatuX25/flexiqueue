<?php

namespace App\Services;

use App\Models\EdgeDeviceState;
use App\Models\EdgeSyncQueueItem;
use App\Models\Session;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EdgeEventPushService
{
    /**
     * Push a single event to central. Returns true on success, false on failure.
     * On failure, the event is queued for retry in edge_sync_queue.
     */
    public function push(
        string $eventType,
        array $payload,
        ?int $transactionLogId = null,
        ?int $sessionId = null,
    ): bool {
        $state = EdgeDeviceState::current();
        $centralUrl = rtrim($state->central_url, '/');
        $deviceToken = $state->device_token;

        if (! $centralUrl || ! $deviceToken) {
            Log::warning('EdgeEventPushService: missing central_url or device_token, queuing.');
            $this->queueForRetry($eventType, $payload, $transactionLogId, $sessionId);
            return false;
        }

        try {
            $response = Http::timeout(5)
                ->withToken($deviceToken)
                ->post("{$centralUrl}/api/edge/event", [
                    'event_type' => $eventType,
                    'payload' => $payload,
                ]);

            if ($response->successful()) {
                return true;
            }

            Log::warning("EdgeEventPushService: central returned {$response->status()}, queuing.", [
                'event_type' => $eventType,
                'transaction_log_id' => $transactionLogId,
            ]);
        } catch (\Throwable $e) {
            Log::warning('EdgeEventPushService: HTTP failed, queuing.', [
                'error' => $e->getMessage(),
                'event_type' => $eventType,
            ]);
        }

        $this->queueForRetry($eventType, $payload, $transactionLogId, $sessionId);
        return false;
    }

    /**
     * Push event with session state snapshot included.
     * Central uses session_state to upsert the queue_sessions table.
     */
    public function pushWithSession(
        string $eventType,
        array $payload,
        Session $session,
        ?int $transactionLogId = null,
    ): bool {
        $state = EdgeDeviceState::current();
        $centralUrl = rtrim($state->central_url, '/');
        $deviceToken = $state->device_token;

        if (! $centralUrl || ! $deviceToken) {
            $this->queueForRetry($eventType, $payload, $transactionLogId, $session->id);
            return false;
        }

        $sessionState = [
            'id' => $session->id,
            'token_id' => $session->token_id,
            'program_id' => $session->program_id,
            'status' => $session->status,
            'alias' => $session->alias,
            'client_category' => $session->client_category,
            'current_station_id' => $session->current_station_id,
            'client_id' => $session->client_id,
            'created_at' => $session->created_at?->toIso8601String(),
            'updated_at' => $session->updated_at?->toIso8601String(),
        ];

        try {
            $response = Http::timeout(5)
                ->withToken($deviceToken)
                ->post("{$centralUrl}/api/edge/event", [
                    'event_type' => $eventType,
                    'payload' => $payload,
                    'session_state' => $sessionState,
                ]);

            if ($response->successful()) {
                return true;
            }
        } catch (\Throwable $e) {
            Log::warning('EdgeEventPushService: HTTP failed (with session).', [
                'error' => $e->getMessage(),
            ]);
        }

        $this->queueForRetry($eventType, $payload, $transactionLogId, $session->id);
        return false;
    }

    /**
     * Queue directly without attempting HTTP push.
     * Used when sync is in degraded mode.
     */
    public function queueOnly(
        string $eventType,
        array $payload,
        ?int $transactionLogId = null,
        ?int $sessionId = null,
    ): void {
        $this->queueForRetry($eventType, $payload, $transactionLogId, $sessionId);
    }

    protected function queueForRetry(
        string $eventType,
        array $payload,
        ?int $transactionLogId,
        ?int $sessionId,
    ): void {
        EdgeSyncQueueItem::create([
            'transaction_log_id' => $transactionLogId,
            'session_id' => $sessionId,
            'event_type' => $eventType,
            'payload' => $payload,
            'attempts' => 1,
            'status' => 'pending',
            'last_attempted_at' => now(),
            'created_at' => now(),
        ]);
    }
}
