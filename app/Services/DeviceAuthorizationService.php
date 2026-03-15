<?php

namespace App\Services;

use App\Models\DeviceAuthorization;
use App\Models\Program;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Per plan Step 5: Device authorization for public display/triage.
 * Creates and validates device authorizations (cookie-based) after PIN/QR verification.
 */
class DeviceAuthorizationService
{
    public const COOKIE_NAME_PREFIX = 'device_auth_';

    public static function cookieNameForProgram(Program $program): string
    {
        return self::COOKIE_NAME_PREFIX . $program->id;
    }

    /**
     * Create or update a device authorization for the program. Caller must have verified
     * the user (PIN/QR) and ensured they are allowed to authorize (supervisor or admin).
     *
     * @param  'session'|'persistent'  $scope
     * @return array{cookie_value: string, authorization_id: int}
     */
    public function authorize(Program $program, string $deviceKey, string $scope): array
    {
        $deviceKeyHash = hash('sha256', $deviceKey);
        $token = Str::random(64);
        $cookieTokenHash = Hash::make($token);

        $auth = DeviceAuthorization::query()
            ->where('program_id', $program->id)
            ->where('device_key_hash', $deviceKeyHash)
            ->first();

        if ($auth) {
            $auth->update([
                'scope' => $scope,
                'cookie_token_hash' => $cookieTokenHash,
            ]);
        } else {
            $auth = DeviceAuthorization::create([
                'program_id' => $program->id,
                'device_key_hash' => $deviceKeyHash,
                'scope' => $scope,
                'cookie_token_hash' => $cookieTokenHash,
            ]);
        }

        $cookieValue = base64_encode($auth->id . '.' . $token);

        return [
            'cookie_value' => $cookieValue,
            'authorization_id' => $auth->id,
        ];
    }

    private const CACHE_TTL_SECONDS = 60;

    /**
     * Check if the request has a valid device authorization cookie for this program.
     * Session-scoped authorizations are valid only while the program is active.
     * Result is cached briefly to avoid DB + bcrypt on every request (reduces lag).
     */
    public function isAuthorized(Program $program, ?string $cookieValue): bool
    {
        if ($cookieValue === null || $cookieValue === '') {
            return false;
        }

        $cacheKey = 'device_auth:'.$program->id.':'.hash('sha256', $cookieValue);
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return (bool) $cached;
        }

        $decoded = base64_decode($cookieValue, true);
        if ($decoded === false) {
            return false;
        }

        $parts = explode('.', $decoded, 2);
        if (count($parts) !== 2) {
            return false;
        }

        [$id, $token] = $parts;
        if (! is_numeric($id) || $token === '') {
            return false;
        }

        $auth = DeviceAuthorization::query()
            ->where('id', (int) $id)
            ->where('program_id', $program->id)
            ->first();

        if (! $auth || ! Hash::check($token, $auth->cookie_token_hash)) {
            Cache::put($cacheKey, false, self::CACHE_TTL_SECONDS);

            return false;
        }

        if ($auth->scope === DeviceAuthorization::SCOPE_SESSION && ! $program->is_active) {
            Cache::put($cacheKey, false, self::CACHE_TTL_SECONDS);

            return false;
        }

        Cache::put($cacheKey, true, self::CACHE_TTL_SECONDS);

        return true;
    }

    /**
     * Remove all device authorizations for the program (e.g. on program delete).
     * FK cascade also removes rows; this allows explicit revocation.
     */
    public function revokeForProgram(Program $program): void
    {
        DeviceAuthorization::query()->where('program_id', $program->id)->delete();
    }
}
