<?php

use App\Models\Program;
use App\Models\Session;
use App\Models\Site;
use App\Models\Token;
use App\Services\ClientService;
use App\Services\Tts\TtsAssetCleanupService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Exit 0 if the app has an "open session" (active program or any active queue session).
 * Exit 1 if no session is open. Used by Pi script to start ZeroTier only when idle.
 * Per docs/architecture/10-DEPLOYMENT.md § ZeroTier timings.
 */
Artisan::command('flexiqueue:session-active', function () {
    $activeProgram = Program::where('is_active', true)->exists();
    $activeSessions = Session::active()->exists();
    $inSession = $activeProgram || $activeSessions;
    if ($inSession) {
        $this->comment('Session active (program or queue).');

        return 0;
    }
    $this->comment('No session active.');

    return 1;
})->purpose('Report if app has active program or queue sessions (exit 0=active, 1=idle).');

/**
 * Mark tokens stuck in "generating" as "failed" (no worker, job died, or orphaned).
 * Uses updated_at: if generating for > N minutes with no progress, assume orphaned.
 */
Artisan::command('tokens:fix-stuck-generating {--minutes=5 : Only mark tokens generating longer than this} {--all : Mark all generating, ignore timeout}', function () {
    $query = Token::where('tts_status', 'generating');
    if (! $this->option('all')) {
        $minutes = (int) $this->option('minutes');
        $query->where('updated_at', '<', now()->subMinutes($minutes));
    }
    $count = $query->update(['tts_status' => 'failed']);
    $this->comment("Marked {$count} token(s) as failed.");
})->purpose('Fix tokens stuck in generating (no worker, job died, or orphaned).');

Artisan::command('e2e:seed-client {extraArgs?*} {--name=} {--birth-year=} {--mobile=}', function (
    ClientService $clientService,
) {
    if (! app()->environment(['local', 'testing'])) {
        $this->error('e2e:seed-client is only allowed in local and testing environments.');

        return 1;
    }

    $name = $this->option('name');
    $birthYear = $this->option('birth-year');
    $mobile = $this->option('mobile');

    if ($name === null || $birthYear === null) {
        $this->error('Missing required options: --name and --birth-year are required. --mobile is optional.');

        return 1;
    }

    $siteId = Site::query()->first()?->id;
    $client = $clientService->createClient($name, (int) $birthYear, $siteId, $mobile);

    $this->info('client_id='.$client->id);

    return 0;
})->purpose('Seed a client for Playwright E2E tests (local/testing only).');

// Scheduler: central deploy + initial setup
Schedule::command('flexiqueue:deploy-update')
    ->everyMinute()
    ->name('deploy-update')
    ->withoutOverlapping();

Schedule::command('flexiqueue:initial-setup')
    ->everyMinute()
    ->name('initial-setup')
    ->withoutOverlapping();

Artisan::command('tts:cleanup-superseded {--days=14 : Retention days before deleting replaced assets} {--limit=200 : Max files to process} {--dry-run : Preview only}', function (
    TtsAssetCleanupService $cleanupService
) {
    $days = (int) $this->option('days');
    $limit = (int) $this->option('limit');
    $dryRun = (bool) $this->option('dry-run');

    $summary = $cleanupService->cleanupSupersededAssets($days, $limit, $dryRun);

    $this->info('TTS cleanup complete.');
    $this->line('dry_run='.($dryRun ? 'true' : 'false'));
    $this->line('candidates='.$summary['candidates']);
    $this->line('scanned='.$summary['scanned']);
    $this->line('deleted='.$summary['deleted']);
})->purpose('Cleanup superseded TTS assets from lifecycle metadata with retention and dry-run support.');

Schedule::command('tts:cleanup-superseded --days=14 --limit=200')
    ->dailyAt('02:30')
    ->name('tts-cleanup-superseded')
    ->withoutOverlapping();

use App\Console\Commands\EdgeHeartbeat;
use App\Console\Commands\EdgeSyncRetry;

Schedule::command(EdgeHeartbeat::class)
    ->everyFiveMinutes()
    ->name('edge-heartbeat')
    ->withoutOverlapping();

Schedule::command(EdgeSyncRetry::class)
    ->everyThirtySeconds()
    ->name('edge-sync-retry')
    ->withoutOverlapping();
