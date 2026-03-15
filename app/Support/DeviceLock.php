<?php

namespace App\Support;

use App\Models\Program;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Per plan: device lock (cookie-based) so a public device stays on the chosen interface
 * (display / triage / station) until unlocked via QR approval.
 *
 * Cookie schema: single cookie "device_lock" with JSON-encoded payload.
 */
class DeviceLock
{
    public const COOKIE_NAME = 'device_lock';

    private const MINUTES = 60 * 24 * 365; // 1 year

    public const TYPE_DISPLAY = 'display';

    public const TYPE_TRIAGE = 'triage';

    public const TYPE_STATION = 'station';

    /** One-time lock token expiry in seconds (redirect follow-up must happen within this). */
    private const LOCK_TOKEN_TTL_SECONDS = 120;

    /**
     * Create a one-time token for the lock payload (used when redirect response Set-Cookie is not sent on follow-up).
     *
     * @param  'display'|'triage'|'station'  $deviceType
     */
    public static function createLockToken(string $siteSlug, string $programSlug, string $deviceType, ?int $stationId = null): string
    {
        $payload = [
            'site_slug' => $siteSlug,
            'program_slug' => $programSlug,
            'device_type' => $deviceType,
            'exp' => now()->addSeconds(self::LOCK_TOKEN_TTL_SECONDS)->timestamp,
        ];
        if ($deviceType === self::TYPE_STATION && $stationId !== null) {
            $payload['station_id'] = $stationId;
        }

        return Crypt::encryptString(json_encode($payload));
    }

    /**
     * Consume a one-time lock token; returns the lock payload or null if invalid/expired.
     *
     * @return array{site_slug: string, program_slug: string, device_type: string, station_id?: int}|null
     */
    public static function consumeLockToken(string $token): ?array
    {
        try {
            $json = Crypt::decryptString($token);
        } catch (\Throwable) {
            return null;
        }
        $data = json_decode($json, true);
        if (! is_array($data) || empty($data['site_slug']) || empty($data['program_slug']) || empty($data['device_type'])) {
            return null;
        }
        $exp = $data['exp'] ?? 0;
        if (! is_numeric($exp) || (int) $exp < time()) {
            return null;
        }
        $type = $data['device_type'];
        if (! in_array($type, [self::TYPE_DISPLAY, self::TYPE_TRIAGE, self::TYPE_STATION], true)) {
            return null;
        }
        $lock = [
            'site_slug' => (string) $data['site_slug'],
            'program_slug' => (string) $data['program_slug'],
            'device_type' => $type,
        ];
        if ($type === self::TYPE_STATION && isset($data['station_id']) && is_numeric($data['station_id'])) {
            $lock['station_id'] = (int) $data['station_id'];
        }

        return $lock;
    }

    public const SESSION_KEY = 'device_lock';

    /**
     * Decode lock from request: cookie first, then session fallback (session is sent every request so lock is enforced even when cookie is not stored).
     *
     * @return array{site_slug: string, program_slug: string, device_type: string, station_id?: int}|null
     */
    public static function decode(Request $request): ?array
    {
        $lock = self::decodeFromCookie($request);
        if ($lock !== null) {
            return $lock;
        }

        return self::decodeFromSession($request);
    }

    /**
     * Decode lock from cookie only.
     *
     * @return array{site_slug: string, program_slug: string, device_type: string, station_id?: int}|null
     */
    private static function decodeFromCookie(Request $request): ?array
    {
        $raw = $request->cookie(self::COOKIE_NAME);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return self::parseLockPayload($raw);
    }

    /**
     * Decode lock from session (fallback when cookie is not stored by browser).
     *
     * @return array{site_slug: string, program_slug: string, device_type: string, station_id?: int}|null
     */
    private static function decodeFromSession(Request $request): ?array
    {
        $data = $request->session()->get(self::SESSION_KEY);
        if (! is_array($data)) {
            return null;
        }

        $lock = self::parseLockPayloadArray($data);

        return $lock;
    }

    /**
     * @return array{site_slug: string, program_slug: string, device_type: string, station_id?: int}|null
     */
    private static function parseLockPayload(string $raw): ?array
    {
        $data = json_decode($raw, true);

        return is_array($data) ? self::parseLockPayloadArray($data) : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{site_slug: string, program_slug: string, device_type: string, station_id?: int}|null
     */
    private static function parseLockPayloadArray(array $data): ?array
    {
        if (empty($data['site_slug']) || empty($data['program_slug']) || empty($data['device_type'])) {
            return null;
        }

        $type = $data['device_type'];
        if (! in_array($type, [self::TYPE_DISPLAY, self::TYPE_TRIAGE, self::TYPE_STATION], true)) {
            return null;
        }

        if ($type === self::TYPE_STATION && (! isset($data['station_id']) || ! is_numeric($data['station_id']))) {
            return null;
        }

        $lock = [
            'site_slug' => (string) $data['site_slug'],
            'program_slug' => (string) $data['program_slug'],
            'device_type' => $type,
        ];
        if ($type === self::TYPE_STATION) {
            $lock['station_id'] = (int) $data['station_id'];
        }

        return $lock;
    }

    /**
     * Store lock in session so EnforceDeviceLock works even when the cookie is not stored by the browser.
     *
     * @param  array{site_slug: string, program_slug: string, device_type: string, station_id?: int}  $lock
     */
    public static function storeInSession(Request $request, array $lock): void
    {
        $request->session()->put(self::SESSION_KEY, $lock);
    }

    /**
     * Remove lock from session (e.g. when clearing device lock or after consume).
     */
    public static function clearFromSession(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
    }

    /**
     * Redirect URL for a lock payload (for client-side guard when back/cache avoids server round-trip).
     *
     * @param  array{site_slug: string, program_slug: string, device_type: string, station_id?: int}  $lock
     */
    public static function redirectUrlForLock(array $lock): ?string
    {
        $siteSlug = $lock['site_slug'];
        $programSlug = $lock['program_slug'];
        $deviceType = $lock['device_type'];
        $stationId = $lock['station_id'] ?? null;

        return match ($deviceType) {
            self::TYPE_DISPLAY => self::displayRedirectUrl($siteSlug, $programSlug),
            self::TYPE_TRIAGE => '/site/'.$siteSlug.'/public-triage/'.$programSlug,
            self::TYPE_STATION => $stationId !== null ? '/site/'.$siteSlug.'/display/station/'.$stationId : null,
            default => null,
        };
    }

    private static function displayRedirectUrl(string $siteSlug, string $programSlug): ?string
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

    /**
     * Build cookie to set on response. Caller attaches via response->cookie().
     *
     * @param  'display'|'triage'|'station'  $deviceType
     * @param  int|null  $stationId  Required when deviceType is 'station'
     */
    public static function encode(string $siteSlug, string $programSlug, string $deviceType, ?int $stationId = null): Cookie
    {
        $payload = [
            'site_slug' => $siteSlug,
            'program_slug' => $programSlug,
            'device_type' => $deviceType,
        ];
        if ($deviceType === self::TYPE_STATION && $stationId !== null) {
            $payload['station_id'] = $stationId;
        }

        $secure = request()->secure();

        return Cookie::create(
            self::COOKIE_NAME,
            json_encode($payload),
            self::MINUTES,
            '/',
            null,
            $secure,
            true,  // httpOnly
            false,
            'lax'
        );
    }

    /**
     * Build cookie that clears the device lock (expire in the past).
     */
    public static function clearCookie(): Cookie
    {
        $secure = request()->secure();

        return Cookie::create(
            self::COOKIE_NAME,
            '',
            -1,
            '/',
            null,
            $secure,
            true,
            false,
            'lax'
        );
    }

    public static function isLocked(Request $request): bool
    {
        return self::decode($request) !== null;
    }

    /**
     * @return 'display'|'triage'|'station'|null
     */
    public static function getLockType(Request $request): ?string
    {
        $lock = self::decode($request);

        return $lock['device_type'] ?? null;
    }

    /**
     * Check if the current lock matches the given context.
     *
     * @param  int|null  $stationId  Required when type is 'station'
     */
    public static function matches(Request $request, string $siteSlug, string $programSlug, string $type, ?int $stationId = null): bool
    {
        $lock = self::decode($request);
        if ($lock === null) {
            return false;
        }
        if ($lock['site_slug'] !== $siteSlug || $lock['program_slug'] !== $programSlug || $lock['device_type'] !== $type) {
            return false;
        }
        if ($type === self::TYPE_STATION) {
            return isset($lock['station_id']) && $lock['station_id'] === $stationId;
        }

        return true;
    }
}
