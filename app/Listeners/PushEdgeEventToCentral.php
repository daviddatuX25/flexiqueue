<?php

namespace App\Listeners;

use App\Events\EdgeSyncableEventCreated;
use App\Services\EdgeEventPushService;
use Illuminate\Support\Facades\Cache;

class PushEdgeEventToCentral
{
    public function __construct(
        private EdgeEventPushService $pushService,
    ) {}

    public function handle(EdgeSyncableEventCreated $event): void
    {
        $log = $event->transactionLog;
        $session = $event->session;

        $payload = [
            'id' => $log->id,
            'session_id' => $log->session_id,
            'station_id' => $log->station_id,
            'staff_user_id' => $log->staff_user_id,
            'action_type' => $log->action_type,
            'previous_station_id' => $log->previous_station_id,
            'next_station_id' => $log->next_station_id,
            'remarks' => $log->remarks,
            'metadata' => $log->metadata,
            'created_at' => $log->created_at->toIso8601String(),
        ];

        // When degraded, skip HTTP and queue directly for retry
        if (Cache::get('edge.sync_degraded', false)) {
            $this->pushService->queueOnly(
                eventType: 'transaction_log',
                payload: $payload,
                transactionLogId: $log->id,
                sessionId: $session->id,
            );
            return;
        }

        $this->pushService->pushWithSession(
            eventType: 'transaction_log',
            payload: $payload,
            session: $session,
            transactionLogId: $log->id,
        );
    }
}
