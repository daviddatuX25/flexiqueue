<?php

namespace App\Console\Commands;

use App\Services\EdgePackageImportService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Import a program package from the central server into this edge Pi.
 * Per docs/final-edge-mode-rush-plann.md [DF-08].
 */
class EdgeImportPackage extends Command
{
    protected $signature = 'edge:import-package
                            {--program= : The program ID to import}
                            {--url= : Override the central URL (optional)}';

    protected $description = 'Import a program package from the central server into this edge Pi.';

    public function handle(EdgePackageImportService $importService): int
    {
        $centralUrl = $this->option('url') ?: env('CENTRAL_URL');
        $apiKey = env('CENTRAL_API_KEY');
        $programId = $this->option('program');

        if (empty($centralUrl) || empty($apiKey) || $programId === null || $programId === '') {
            $this->error('CENTRAL_URL, CENTRAL_API_KEY and --program are required. Set in .env or pass --program=ID and optionally --url=URL.');
            return Command::FAILURE;
        }

        $programId = (int) $programId;
        $lockPath = storage_path('app/edge_import_running.lock');

        if (file_exists($lockPath)) {
            $this->error('Another import is already running.');
            return Command::FAILURE;
        }

        file_put_contents($lockPath, now()->toIso8601String());

        try {
            $importService->runImport($programId, $centralUrl, $apiKey);
            $this->info('Edge import complete.');
            return Command::SUCCESS;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
            return Command::FAILURE;
        } finally {
            @unlink($lockPath);
        }
    }
}
