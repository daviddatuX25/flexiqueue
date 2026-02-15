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

    /**
     * Generate a one-time 6-digit PIN for supervisor authorization.
     *
     * @return array{code: string, expires_at: string, expires_in_seconds: int}
     */
    public function generate(User $user, ?int $programId = null, ?int $expiresInSeconds = null): array
    {
        $ttl = $expiresInSeconds ?? self::DEFAULT_TTL_SECONDS;
        $code = $this->generateCode();
        $expiresAt = now()->addSeconds($ttl);

        TemporaryAuthorization::create([
            'user_id' => $user->id,
            'token_hash' => Hash::make($code),
            'type' => 'pin',
            'expires_at' => $expiresAt,
        ]);

        return [
            'code' => $code,
            'expires_at' => $expiresAt->toIso8601String(),
            'expires_in_seconds' => $ttl,
        ];
    }

    private function generateCode(): string
    {
        return (string) random_int(100000, 999999);
    }
}
