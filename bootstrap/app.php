<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: [
            \App\Support\DeviceLock::COOKIE_NAME,
            'known_sites',
            'known_programs',
        ]);
        // Public key-entry APIs: auth by key + cookie only (no session); CSRF would block same-origin form POSTs.
        $middleware->validateCsrfTokens(except: [
            'api/public/program-key',
            'api/public/site-key',
        ]);
        $middleware->web(append: [
            \App\Http\Middleware\EdgeBootGuard::class,
            \App\Http\Middleware\EnforceDeviceLock::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
            \App\Http\Middleware\AddPermissionsPolicy::class,
        ]);
        $middleware->alias([
            'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class,
            'role' => \App\Http\Middleware\EnsureRole::class,
            'site.api_key' => \App\Http\Middleware\AuthenticateSiteByApiKey::class,
            'require.site.access' => \App\Http\Middleware\RequireSiteAccess::class,
            'require.program.access' => \App\Http\Middleware\RequireProgramAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
