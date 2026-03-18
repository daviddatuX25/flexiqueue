<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class RunInitialSetup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Mirrors the initial-setup helper in php-run-scripts, but runs inside
     * the normal Laravel artisan context and self-disables via a flag file.
     */
    protected $signature = 'flexiqueue:initial-setup';

    /**
     * The console command description.
     */
    protected $description = 'Run first-time initial setup tasks when not yet marked done';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $doneFlag = base_path('bootstrap/cache/initial_setup_done');

        if (file_exists($doneFlag)) {
            $this->info('Initial setup already marked as done. Nothing to do.');

            return self::SUCCESS;
        }

        // Reuse the shared helper logic without re-bootstrapping the app.
        require_once base_path('php-run-scripts/helpers.php');

        try {
            run_initial_setup(app());
            $this->info('Initial setup tasks completed.');
        } catch (\Throwable $e) {
            $this->error('Error during initial-setup: '.$e->getMessage());

            return self::FAILURE;
        }

        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($doneFlag, $timestamp);
        $this->info("initial_setup_done flag written at {$timestamp}.");

        return self::SUCCESS;
    }
}

