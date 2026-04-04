<?php

namespace App\Console\Commands;

use App\Models\EdgeDeviceState;
use App\Models\EdgeSyncQueueItem;
use App\Services\EdgeModeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EdgeSyncRetry extends Command
{
    protected $signature = 'edge:sync-retry';
    protected $description = 'Retry pending items in the edge sync queue (runs every 30s on edge)';

    private const MAX_ATTEMPTS = 5;
    private const BATCH_SIZE = 50;

    public function handle(EdgeModeService $edgeModeService): int
    {
        if (! $edgeModeService->isEdge()) {
            $this->info('Not running on edge — skipping.');
            return self::SUCCESS;
        }

        $state = EdgeDeviceState::current();
        if ($state->sync_mode !== 'auto') {
            $this->info('Sync mode is not auto — skipping.');
            return self::SUCCESS;
        }

        $pending = EdgeSyncQueueItem::retryable()
            ->limit(self::BATCH_SIZE)
            ->get();

        if ($pending->isEmpty()) {
            return self::SUCCESS;
        }

        $centralUrl = rtrim($state->central_url, '/');
        $deviceToken = $state->device_token;

        if (! $centralUrl || ! $deviceToken) {
            $this->warn('Missing central_url or device_token.');
            return self::SUCCESS;
        }

        $anySuccess = false;

        foreach ($pending as $item) {
            try {
                $response = Http::timeout(5)
                    ->withToken($deviceToken)
                    ->post("{$centralUrl}/api/edge/event", [
                        'event_type' => $item->event_type,
                        'payload' => $item->payload,
                    ]);

                if ($response->successful()) {
                    $item->markSent();
                    $anySuccess = true;
                    continue;
                }
            } catch (\Throwable $e) {
                Log::warning('EdgeSyncRetry: push failed', [
                    'item_id' => $item->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Failed
            $item->incrementAttempt();

            if ($item->attempts >= self::MAX_ATTEMPTS) {
                $item->markFailed();
                // Degrade when any item exhausts all retries
                if (! Cache::get('edge.sync_degraded', false)) {
                    Cache::put('edge.sync_degraded', true, 3600);
                    Log::warning('EdgeSyncRetry: degraded to local-only after item exceeded max attempts.', [
                        'item_id' => $item->id,
                        'transaction_log_id' => $item->transaction_log_id,
                    ]);
                    $this->warn('Degraded to local-only mode.');
                }
            }
        }

        // Resume logic: if any push succeeded, clear degrade flag
        if ($anySuccess && Cache::get('edge.sync_degraded', false)) {
            Cache::forget('edge.sync_degraded');
            Log::info('EdgeSyncRetry: connectivity restored, resuming auto-sync.');
            $this->info('Connectivity restored — auto-sync resumed.');
        }

        return self::SUCCESS;
    }
}
