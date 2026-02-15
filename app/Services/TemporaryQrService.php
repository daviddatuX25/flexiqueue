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

    public function __construct(
        private TokenPrintService $tokenPrintService
    ) {}

    /**
     * Generate a one-time QR for supervisor authorization. Staff scans to get token.
     *
     * @return array{qr_data_uri: string, expires_at: string, expires_in_seconds: int}
     */
    public function generate(User $user, ?int $programId = null, ?int $expiresInSeconds = null): array
    {
        $ttl = $expiresInSeconds ?? self::DEFAULT_TTL_SECONDS;
        $token = Str::random(64);
        $expiresAt = now()->addSeconds($ttl);

        TemporaryAuthorization::create([
            'user_id' => $user->id,
            'token_hash' => Hash::make($token),
            'type' => 'qr',
            'expires_at' => $expiresAt,
        ]);

        $qrDataUri = $this->tokenPrintService->generateQrDataUri($token);

        return [
            'qr_data_uri' => $qrDataUri,
            'expires_at' => $expiresAt->toIso8601String(),
            'expires_in_seconds' => $ttl,
        ];
    }
}
