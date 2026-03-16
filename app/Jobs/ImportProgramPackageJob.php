<?php

namespace App\Jobs;

use App\Services\EdgePackageImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Import program package from central (edge Pi). Writes lock and status file.
 * Per docs/final-edge-mode-rush-plann.md [DF-09].
 */
class ImportProgramPackageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public function __construct(
        public int $programId,
        public string $centralUrl,
        public string $apiKey
    ) {}

    public function handle(EdgePackageImportService $importService): void
    {
        $lockPath = storage_path('app/edge_import_running.lock');
        file_put_contents($lockPath, now()->toIso8601String());

        Storage::disk('local')->put('edge_package_imported.json', json_encode([
            'status' => 'running',
            'started_at' => now()->toIso8601String(),
        ]));

        try {
            $importService->runImport($this->programId, $this->centralUrl, $this->apiKey);
        } catch (Throwable $e) {
            Log::error('Edge package import failed: ' . $e->getMessage(), ['exception' => $e]);
            Storage::disk('local')->put('edge_package_imported.json', json_encode([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'failed_at' => now()->toIso8601String(),
            ]));
        } finally {
            if (file_exists($lockPath)) {
                @unlink($lockPath);
            }
        }
    }
}
