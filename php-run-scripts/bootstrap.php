<?php

/**
 * Emergency bootstrap script.
 * Runs without Laravel being booted — safe when config cache is broken.
 * Called by Hestia cron or Run PHP panel to recover a broken deployment.
 */

$appRoot = dirname(__DIR__);
$php = '/usr/bin/php8.2';
$artisan = $appRoot . '/artisan';

function run(string $php, string $artisan, string $command): void
{
    echo "Running: php artisan $command\n";
    $output = shell_exec("$php $artisan $command 2>&1");
    echo $output . "\n";
}

// Step 1 — Generate APP_KEY if missing
$envFile = $appRoot . '/.env';
if (is_file($envFile)) {
    $env = file_get_contents($envFile);
    if ($env !== false && strpos($env, 'APP_KEY=base64:') === false) {
        run($php, $artisan, 'key:generate --force');
    }
}

// Step 2 — Clear broken config cache first
$cacheFiles = glob($appRoot . '/bootstrap/cache/*.php');
if (is_array($cacheFiles)) {
    foreach ($cacheFiles as $f) {
        if (is_file($f)) {
            @unlink($f);
            echo "Deleted cache: " . basename($f) . "\n";
        }
    }
}

// Step 3 — Run migrations
run($php, $artisan, 'migrate --force');

// Step 4 — Seed superadmin
run($php, $artisan, 'db:seed --class=SuperAdminSeeder --force');

// Step 5 — Regenerate caches
run($php, $artisan, 'config:cache');
run($php, $artisan, 'route:cache');
run($php, $artisan, 'storage:link');

// Step 6 — Write done flag
$doneFlag = $appRoot . '/bootstrap/cache/initial_setup_done';
@file_put_contents($doneFlag, date('Y-m-d H:i:s'));
echo "Written: initial_setup_done\n";

// Step 7 — Remove deploy_pending marker if exists
$marker = $appRoot . '/bootstrap/cache/deploy_pending';
if (file_exists($marker)) {
    unlink($marker);
    echo "Removed deploy_pending marker.\n";
}

echo "\nBootstrap complete. Site should be working now.\n";
