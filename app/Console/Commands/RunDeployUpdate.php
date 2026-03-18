<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class RunDeployUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Mirrors the deploy-update helper in php-run-scripts, but runs inside
     * the normal Laravel artisan context and cleans up the deploy marker.
     */
    protected $signature = 'flexiqueue:deploy-update';

    /**
     * The console command description.
     */
    protected $description = 'Run deploy-update tasks when deploy_pending marker exists';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $marker = base_path('bootstrap/cache/deploy_pending');

        if (! file_exists($marker)) {
            $this->info('No deploy_pending marker found; nothing to do.');

            return self::SUCCESS;
        }

        // Reuse the shared helper logic without re-bootstrapping the app.
        require_once base_path('php-run-scripts/helpers.php');

        try {
            run_deploy_update(app());
            $this->info('Deploy update tasks completed.');
        } catch (\Throwable $e) {
            $this->error('Error during deploy-update: '.$e->getMessage());
        } finally {
            if (file_exists($marker)) {
                @unlink($marker);
                $this->info('deploy_pending marker removed.');
            }
        }

        return self::SUCCESS;
    }
}

