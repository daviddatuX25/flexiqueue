<?php

namespace Tests\Unit\Services;

use App\Models\Token;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Unit tests for TokenService. Per docs/REFACTORING-ISSUE-LIST.md Issue 4: lookupByPhysicalOrHash().
 */
class TokenServiceTest extends TestCase
{
    use RefreshDatabase;

    private TokenService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TokenService::class);
    }

    public function test_lookup_by_qr_hash_returns_token(): void
    {
        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).'A1');
        $token->physical_id = 'A1';
        $token->status = 'available';
        $token->save();

        $result = $this->service->lookupByPhysicalOrHash(null, $token->qr_code_hash);

        $this->assertInstanceOf(Token::class, $result);
        $this->assertSame($token->id, $result->id);
        $this->assertSame($token->qr_code_hash, $result->qr_code_hash);
        $this->assertSame('A1', $result->physical_id);
    }

    public function test_lookup_by_physical_id_returns_token(): void
    {
        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).'B2');
        $token->physical_id = 'B2';
        $token->status = 'available';
        $token->save();

        $result = $this->service->lookupByPhysicalOrHash($token->physical_id, null);

        $this->assertInstanceOf(Token::class, $result);
        $this->assertSame($token->id, $result->id);
        $this->assertSame('B2', $result->physical_id);
    }

    public function test_lookup_precedence_qr_hash_over_physical_id(): void
    {
        $token1 = new Token;
        $token1->qr_code_hash = hash('sha256', Str::random(32).'X1');
        $token1->physical_id = 'X1';
        $token1->status = 'available';
        $token1->save();

        $token2 = new Token;
        $token2->qr_code_hash = hash('sha256', Str::random(32).'X2');
        $token2->physical_id = 'X2';
        $token2->status = 'available';
        $token2->save();

        $result = $this->service->lookupByPhysicalOrHash('X2', $token1->qr_code_hash);

        $this->assertInstanceOf(Token::class, $result);
        $this->assertSame($token1->id, $result->id);
        $this->assertSame($token1->qr_code_hash, $result->qr_code_hash);
    }

    public function test_lookup_not_found_returns_null(): void
    {
        $result = $this->service->lookupByPhysicalOrHash('NONEXISTENT', null);

        $this->assertNull($result);
    }

    public function test_lookup_both_empty_returns_null(): void
    {
        $this->assertNull($this->service->lookupByPhysicalOrHash(null, null));
        $this->assertNull($this->service->lookupByPhysicalOrHash('', ''));
    }
}
