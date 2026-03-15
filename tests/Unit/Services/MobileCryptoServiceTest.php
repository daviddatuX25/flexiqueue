<?php

namespace Tests\Unit\Services;

use App\Services\MobileCryptoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per PRIVACY-BY-DESIGN-IDENTITY-BINDING.md: hash/encrypt/decrypt roundtrip and mask output.
 * Write before using this service anywhere else.
 */
class MobileCryptoServiceTest extends TestCase
{
    use RefreshDatabase;

    private MobileCryptoService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(MobileCryptoService::class);
    }

    public function test_hash_produces_sha256_of_normalized_mobile(): void
    {
        $mobile = '09171234567';
        $hash = $this->service->hash($mobile);

        $this->assertSame(64, strlen($hash));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);

        $hash2 = $this->service->hash('+639171234567');
        $this->assertSame($hash, $hash2, 'Normalized variants must produce same hash');
    }

    public function test_encrypt_decrypt_roundtrip(): void
    {
        $mobile = '09171234567';
        $encrypted = $this->service->encrypt($mobile);
        $this->assertNotSame($mobile, $encrypted);
        $this->assertNotEmpty($encrypted);

        $decrypted = $this->service->decrypt($encrypted);
        $this->assertSame($mobile, $decrypted);
    }

    public function test_encrypt_normalizes_before_storage(): void
    {
        $encrypted = $this->service->encrypt('+63917 123-4567');
        $decrypted = $this->service->decrypt($encrypted);
        $this->assertSame('09171234567', $decrypted);
    }

    public function test_mask_shows_first_4_middle_masked_last_2(): void
    {
        $mobile = '09171234567';
        $masked = $this->service->mask($mobile);

        $this->assertSame('0917-***-**67', $masked);
    }

    public function test_mask_normalizes_before_masking(): void
    {
        $masked = $this->service->mask('+639171234567');
        $this->assertSame('0917-***-**67', $masked);
    }
}
