<?php

/**
 * Deployment update helper for codebase changes.
 *
 * Intended to be run after pulling new code or uploading a new build:
 * - Run database migrations (with --force)
 * - Rebuild config and route cache
 *
 * Usage (from app root):
 *   php php-run-scripts/deploy-update.php
 *
 * Or from a hosting panel "Run PHP" pointing at this file.
 */

$app = require __DIR__ . '/bootstrap.php';
require __DIR__ . '/helpers.php';

run_deploy_update($app);

// Always clear the deploy_pending marker if present, regardless of success.
$markerPath = __DIR__ . '/../bootstrap/cache/deploy_pending';
if (file_exists($markerPath)) {
    unlink($markerPath);
    echo "[deploy-update] Marker file removed.\n";
}

