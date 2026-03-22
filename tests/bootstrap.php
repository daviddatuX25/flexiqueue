<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap (see phpunit.xml).
 *
 * If `php artisan config:cache` was run for local dev, Laravel skips loading .env
 * and uses bootstrap/cache/config.php — which embeds your real DB (e.g. MySQL).
 * That makes PHPUnit hit your main database and forces re-seeding.
 *
 * Remove cached config before autoload so tests always use phpunit.xml + .env.testing
 * (sqlite :memory:).
 */
$basePath = dirname(__DIR__);

$cachedConfig = $basePath.'/bootstrap/cache/config.php';
if (is_file($cachedConfig)) {
    @unlink($cachedConfig);
}

require $basePath.'/vendor/autoload.php';
