<?php

namespace App\Console\Commands;

use App\Models\EdgeDeviceState;
use App\Services\EdgeBatchSyncService;
use App\Services\EdgeModeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EdgeAutoSync extends Command
{
    protected $signature = 'edge:auto-sync';
    protected $description = 'Trigger batch sync at scheduled time (end-of-event mode only)';

    private const RETRY_INTERVAL_MINUTES = 15;
    private const RETRY_WINDOW_HOURS = 2;

    public function handle(EdgeModeService $edgeModeService, EdgeBatchSyncService $syncService): int
    {
        if (! $edgeModeService->isEdge()) {
            $this->info('Not running on edge — skipping.');
            return self::SUCCESS;
        }

        $state = EdgeDeviceState::current();

        if ($state->sync_mode !== 'end_of_event') {
            $this->info('Sync mode is not end_of_event — skipping.');
            return self::SUCCESS;
        }

        if (! $this->shouldRunNow($state)) {
            $this->info('Not scheduled sync time — skipping.');
            return self::SUCCESS;
        }

        if (! $syncService->hasUnsyncedData()) {
            $this->info('No unsynced data — skipping.');
            $this->clearRetryState();
            return self::SUCCESS;
        }

        $this->info('Starting batch sync...');
        $success = $syncService->pushToCentral();

        if ($success) {
            $this->info('Batch sync complete.');
            $this->clearRetryState();
            return self::SUCCESS;
        }

        $this->warn('Batch sync failed — scheduling retry.');
        $this->scheduleRetry();
        return self::SUCCESS;
    }

    private function shouldRunNow(EdgeDeviceState $state): bool
    {
        // Check if we're in a retry window
        $retryUntil = Cache::get('edge.auto_sync_retry_until');
        if ($retryUntil && now()->lt($retryUntil)) {
            $nextRetry = Cache::get('edge.auto_sync_next_retry');
            if ($nextRetry && now()->gte($nextRetry)) {
                return true;
            }
            return false;
        }

        // Check if current time matches scheduled time (within 1-minute window)
        $scheduledTime = $state->scheduled_sync_time ?? '17:00';
        $currentTime = now()->format('H:i');

        return $currentTime === $scheduledTime;
    }

    private function scheduleRetry(): void
    {
        $retryUntil = Cache::get('edge.auto_sync_retry_until');

        if (! $retryUntil) {
            // First failure — start retry window
            $retryUntil = now()->addHours(self::RETRY_WINDOW_HOURS)->toIso8601String();
            Cache::put('edge.auto_sync_retry_until', $retryUntil, self::RETRY_WINDOW_HOURS * 3600);
            Log::info('EdgeAutoSync: retry window started, expires at ' . $retryUntil);
        }

        // Schedule next retry in 15 minutes
        $nextRetry = now()->addMinutes(self::RETRY_INTERVAL_MINUTES)->toIso8601String();
        Cache::put('edge.auto_sync_next_retry', $nextRetry, self::RETRY_WINDOW_HOURS * 3600);
    }

    private function clearRetryState(): void
    {
        Cache::forget('edge.auto_sync_retry_until');
        Cache::forget('edge.auto_sync_next_retry');
    }
}
