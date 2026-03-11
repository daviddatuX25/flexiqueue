<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicClientLookupRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_client_lookup_is_rate_limited(): void
    {
        // First N requests within limit should succeed (or return non-429).
        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson('/api/public/clients/lookup-by-id', [
                'id_type' => 'PhilHealth',
                'id_number' => 'XX-0000-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            ]);

            $this->assertNotSame(
                429,
                $response->getStatusCode(),
                'Unexpected 429 before exceeding rate limit'
            );
        }

        // N+1 request should be rejected with 429 Too Many Requests.
        $response = $this->postJson('/api/public/clients/lookup-by-id', [
            'id_type' => 'PhilHealth',
            'id_number' => 'XX-0000-9999',
        ]);

        $response->assertStatus(429);
    }
}

