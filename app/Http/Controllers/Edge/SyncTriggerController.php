<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Models\EdgeDeviceState;
use App\Models\EdgeSyncReceipt;
use App\Services\EdgeBatchSyncService;
use Illuminate\Http\JsonResponse;

class SyncTriggerController extends Controller
{
    public function trigger(EdgeBatchSyncService $syncService): JsonResponse
    {
        if (! $syncService->hasUnsyncedData()) {
            return response()->json([
                'status' => 'no_data',
                'message' => 'No unsynced data to send.',
            ]);
        }

        $success = $syncService->pushToCentral();

        if ($success) {
            return response()->json([
                'status' => 'complete',
                'message' => 'Batch sync completed successfully.',
            ]);
        }

        return response()->json([
            'status' => 'failed',
            'message' => 'Batch sync failed. Will retry automatically.',
        ], 502);
    }

    public function status(): JsonResponse
    {
        $state = EdgeDeviceState::current();
        $syncService = app(EdgeBatchSyncService::class);

        $lastReceipt = EdgeSyncReceipt::latest('id')->first();

        return response()->json([
            'sync_mode' => $state->sync_mode,
            'last_synced_at' => $state->last_synced_at?->toIso8601String(),
            'scheduled_sync_time' => $state->scheduled_sync_time,
            'has_unsynced_data' => $syncService->hasUnsyncedData(),
            'last_receipt' => $lastReceipt ? [
                'batch_id' => $lastReceipt->batch_id,
                'status' => $lastReceipt->status,
                'payload_summary' => $lastReceipt->payload_summary,
                'receipt_data' => $lastReceipt->receipt_data,
                'started_at' => $lastReceipt->started_at?->toIso8601String(),
                'completed_at' => $lastReceipt->completed_at?->toIso8601String(),
            ] : null,
        ]);
    }

    public function receipts(): JsonResponse
    {
        $receipts = EdgeSyncReceipt::orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn ($r) => [
                'batch_id' => $r->batch_id,
                'status' => $r->status,
                'payload_summary' => $r->payload_summary,
                'receipt_data' => $r->receipt_data,
                'started_at' => $r->started_at?->toIso8601String(),
                'completed_at' => $r->completed_at?->toIso8601String(),
                'created_at' => $r->created_at?->toIso8601String(),
            ]);

        return response()->json(['receipts' => $receipts]);
    }
}
