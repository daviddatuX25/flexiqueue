<?php

namespace App\Http\Middleware;

use App\Models\Program;
use App\Models\Site;
use App\Support\DeviceLock;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per plan: when device has device_lock cookie, allow only the locked device route (display, triage, or station).
 * No home, no choose-device page—back navigation and logo lead back to the locked URL. Exit only via admin scan QR → approve → device-lock/clear.
 */
class EnforceDeviceLock
{
    /** Path prefixes that are never enforced (API, auth, etc.). */
    private const SKIP_PREFIXES = ['api/', 'sanctum/', 'go/'];

    /** Path segments that identify auth or other non-public pages we skip. */
    private const SKIP_PATHS = ['login', 'register', 'password', 'email', 'verify', 'two-factor', 'forgot-password', 'reset-password'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET')) {
            return $next($request);
        }

        // Per public-site plan: read-only board and program info page allowed without device lock.
        $routeName = $request->route()?->getName();
        if (in_array($routeName, ['site.program.public-view', 'site.program.info'], true)) {
            return $next($request);
        }

        $path = $request->path();
        if ($path === '') {
            $path = '/';
        }

        foreach (self::SKIP_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $next($request);
            }
        }

        $segments = explode('/', $path);
        $first = $segments[0] ?? '';
        if (in_array($first, self::SKIP_PATHS, true)) {
            return $next($request);
        }

        $lock = DeviceLock::decode($request);
        if ($lock === null) {
            return $next($request);
        }

        $siteSlug = $lock['site_slug'];
        $programSlug = $lock['program_slug'];
        $deviceType = $lock['device_type'];
        $stationId = $lock['station_id'] ?? null;

        $allowedPrefixes = $this->allowedPathPrefixes($siteSlug, $programSlug, $deviceType, $stationId);
        $allowed = false;
        foreach ($allowedPrefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                $allowed = true;
                break;
            }
        }
        $redirectUrl = $this->redirectUrlForLock($siteSlug, $programSlug, $deviceType, $stationId);
        if ($allowed) {
            return $next($request);
        }
        if ($redirectUrl === null) {
            return $next($request);
        }

        return redirect()->to($redirectUrl, 302);
    }

    /**
     * Allowed path prefixes for this lock (path without leading slash).
     *
     * @return array<string>
     */
    private function allowedPathPrefixes(string $siteSlug, string $programSlug, string $deviceType, ?int $stationId): array
    {
        $base = 'site/'.$siteSlug;

        return match ($deviceType) {
            DeviceLock::TYPE_DISPLAY => [
                $base.'/display',
            ],
            DeviceLock::TYPE_TRIAGE => [
                $base.'/public-triage/'.$programSlug,
                $base.'/kiosk/'.$programSlug,
            ],
            DeviceLock::TYPE_KIOSK => [
                $base.'/kiosk/'.$programSlug,
            ],
            DeviceLock::TYPE_STATION => [
                $base.'/display/station/'.$stationId,
            ],
            default => [],
        };
    }

    /**
     * Build redirect URL for the lock (with leading slash).
     */
    private function redirectUrlForLock(string $siteSlug, string $programSlug, string $deviceType, ?int $stationId): ?string
    {
        return match ($deviceType) {
            DeviceLock::TYPE_DISPLAY => $this->displayLockRedirectUrl($siteSlug, $programSlug),
            DeviceLock::TYPE_TRIAGE => '/site/'.$siteSlug.'/kiosk/'.$programSlug,
            DeviceLock::TYPE_KIOSK => '/site/'.$siteSlug.'/kiosk/'.$programSlug,
            DeviceLock::TYPE_STATION => '/site/'.$siteSlug.'/display/station/'.$stationId,
            default => null,
        };
    }

    private function displayLockRedirectUrl(string $siteSlug, string $programSlug): ?string
    {
        $site = Site::query()->where('slug', $siteSlug)->first();
        if (! $site) {
            return null;
        }

        $programId = Program::query()
            ->where('site_id', $site->id)
            ->where('slug', $programSlug)
            ->where('is_active', true)
            ->value('id');

        if ($programId === null) {
            return '/site/'.$siteSlug.'/display';
        }

        return '/site/'.$siteSlug.'/display?program='.$programId;
    }
}
