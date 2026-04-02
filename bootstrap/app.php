<?php

use App\Http\Middleware\AddPermissionsPolicy;
use App\Http\Middleware\AuthenticateSiteByApiKey;
use App\Http\Middleware\BlockOnEdge;
use App\Http\Middleware\EdgeBootGuard;
use App\Http\Middleware\EnforceDeviceLock;
use App\Http\Middleware\EnforcePendingAssignment;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\RequireProgramAccess;
use App\Http\Middleware\RequireSiteAccess;
use App\Http\Middleware\SetGlobalPermissionsTeam;
use App\Support\DeviceLock;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: [
            DeviceLock::COOKIE_NAME,
            'known_sites',
            'known_programs',
        ]);
        // Public key-entry APIs: auth by key + cookie only (no session); CSRF would block same-origin form POSTs.
        $middleware->validateCsrfTokens(except: [
            'api/public/program-key',
            'api/public/site-key',
        ]);
        $middleware->web(append: [
            SetGlobalPermissionsTeam::class,
            EdgeBootGuard::class,
            EnforceDeviceLock::class,
            HandleInertiaRequests::class,
            AddPermissionsPolicy::class,
            EnforcePendingAssignment::class,
        ]);
        $middleware->alias([
            'guest' => RedirectIfAuthenticated::class,
            'permission' => PermissionMiddleware::class,
            'role.permission' => RoleOrPermissionMiddleware::class,
            'spatie.role' => RoleMiddleware::class,
            'site.api_key' => AuthenticateSiteByApiKey::class,
            'require.site.access' => RequireSiteAccess::class,
            'require.program.access' => RequireProgramAccess::class,
            'not.edge' => \App\Http\Middleware\BlockOnEdge::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
