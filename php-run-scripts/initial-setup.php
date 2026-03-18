<?php

/**
 * Initial production setup helper.
 *
 * Intended to be run once after a fresh deploy:
 * - Generate APP_KEY
 * - Create storage symlink
 * - Run database migrations (with --force)
 * - Cache config and routes
 *
 * Usage (from app root):
 *   php php-run-scripts/initial-setup.php
 *
 * Or from a hosting panel "Run PHP" pointing at this file.
 */
$app = require __DIR__ . '/bootstrap.php';
require __DIR__ . '/helpers.php';

run_initial_setup($app);

// Write a self-disabling flag so the initial-setup cron only runs once.
$doneFlag = __DIR__ . '/../bootstrap/cache/initial_setup_done';
file_put_contents($doneFlag, date('Y-m-d H:i:s'));
echo "[initial-setup] Done flag written. Cron will not run again.\n";

