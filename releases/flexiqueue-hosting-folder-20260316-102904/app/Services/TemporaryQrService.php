<?php

namespace App\Services;

use App\Models\TemporaryAuthorization;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Per docs/plans/PIN-QR-AUTHORIZATION-SYSTEM.md AUTH-4: Temporary QR generation.
 */
class TemporaryQrService
{
    private const DEFAULT_TTL_SECONDS = 300;

    private const NO_EXPIRY_SENTINEL = 315360000; // 10 years

    public function __construct(
        private TokenPrintService $tokenPrintService
    ) {}

    /**
     * Generate a temporary QR for supervisor authorization. Staff scans to get token.
     *
     * @return array{qr_data_uri: string, scan_token: string, expiry_mode: string, expires_at: string|null, expires_in_seconds: int|null, max_uses: int|null}
     */
    public function generate(
        User $user,
        ?int $programId = null,
        string $expiryMode = 'time_or_usage',
        ?int $expiresInSeconds = null,
        ?int $maxUses = null
    ): array
    {
        $token = Str::random(64);

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
            'token_hash' => Hash::make($token),
            'type' => 'qr',
            'expiry_mode' => $expiryMode,
            'expires_at' => $expiresAt,
            'max_uses' => $expiryMode === 'time_only' ? null : $maxUses,
            'used_count' => 0,
        ]);

        $qrDataUri = $this->tokenPrintService->generateQrDataUri($token);

        return [
            'qr_data_uri' => $qrDataUri,
            'scan_token' => $token,
            'expiry_mode' => $expiryMode,
            'expires_at' => $expiresAt?->toIso8601String(),
            'expires_in_seconds' => $ttl,
            'max_uses' => $expiryMode === 'time_only' ? null : $maxUses,
        ];
    }
}
