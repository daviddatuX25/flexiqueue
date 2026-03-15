<?php

namespace App\Services;

use App\Support\MobileNormalizer;
use Illuminate\Support\Facades\Crypt;

/**
 * Per PRIVACY-BY-DESIGN-IDENTITY-BINDING.md: hash, encrypt, decrypt, mask for mobile numbers.
 * Uses Laravel Crypt (AES-256-CBC).
 */
final class MobileCryptoService
{
    public function hash(string $mobile): string
    {
        $normalized = MobileNormalizer::normalize($mobile);

        return hash('sha256', $normalized);
    }

    public function encrypt(string $mobile): string
    {
        $normalized = MobileNormalizer::normalize($mobile);

        return Crypt::encryptString($normalized);
    }

    public function decrypt(string $encrypted): string
    {
        return Crypt::decryptString($encrypted);
    }

    /**
     * Per spec: show first 4 digits, mask middle, show last 2.
     * Format: 0917-***-**67
     */
    public function mask(string $mobile): string
    {
        $normalized = MobileNormalizer::normalize($mobile);
        $len = strlen($normalized);

        if ($len < 6) {
            return str_repeat('*', $len);
        }

        $first4 = substr($normalized, 0, 4);
        $last2 = substr($normalized, -2);

        return $first4 . '-***-**' . $last2;
    }
}
