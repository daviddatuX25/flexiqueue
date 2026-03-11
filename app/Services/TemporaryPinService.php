<?php

namespace App\Services;

use App\Models\TemporaryAuthorization;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Per docs/plans/PIN-QR-AUTHORIZATION-SYSTEM.md AUTH-3: Temporary PIN generation.
 */
class TemporaryPinService
{
    private const DEFAULT_TTL_SECONDS = 300;

    private const NO_EXPIRY_SENTINEL = 315360000; // 10 years

    /**
     * Generate a temporary 6-digit PIN for supervisor authorization.
     *
     * @return array{code: string, expiry_mode: string, expires_at: string|null, expires_in_seconds: int|null, max_uses: int|null}
     */
    public function generate(
        User $user,
        ?int $programId = null,
        string $expiryMode = 'time_or_usage',
        ?int $expiresInSeconds = null,
        ?int $maxUses = null
    ): array
    {
        $code = $this->generateCode();

        $expiresAt = null;
        $ttl = null;
        if ($expiryMode !== 'usage_only') {
            $ttl = $expiresInSeconds === 0
                ? self::NO_EXPIRY_SENTINEL
                : ($expiresInSeconds ?? self::DEFAULT_TTL_SECONDS);
            $expiresAt = now()->addSeconds($ttl);
        }

        TemporaryAuthorization::create([
            'user_id' => $user->id,
            'token_hash' => Hash::make($code),
            'type' => 'pin',
            'expiry_mode' => $expiryMode,
            'expires_at' => $expiresAt,
            'max_uses' => $expiryMode === 'time_only' ? null : $maxUses,
            'used_count' => 0,
        ]);

        return [
            'code' => $code,
            'expiry_mode' => $expiryMode,
            'expires_at' => $expiresAt?->toIso8601String(),
            'expires_in_seconds' => $ttl,
            'max_uses' => $expiryMode === 'time_only' ? null : $maxUses,
        ];
    }

    private function generateCode(): string
    {
        return (string) random_int(100000, 999999);
    }
}
