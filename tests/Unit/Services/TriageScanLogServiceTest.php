<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\TriageScanLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Unit tests for TriageScanLogService::log().
 * Per REFACTORING-ISSUE-LIST.md Issue 2: triage scan logging moved from SessionController.
 */
class TriageScanLogServiceTest extends TestCase
{
    use RefreshDatabase;

    private TriageScanLogService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TriageScanLogService::class);
    }

    public function test_log_inserts_row_with_request_query_and_optional_user(): void
    {
        $user = User::factory()->create();
        $request = Request::create('http://test/api/sessions/token-lookup?physical_id=A1&qr_hash=abc123', 'GET');
        $request->setUserResolver(fn () => $user);

        $this->service->log($request, 42, 'available', 'A1', 'abc123');

        $this->assertDatabaseHas('triage_scan_log', [
            'physical_id' => 'A1',
            'qr_hash' => 'abc123',
            'result' => 'available',
            'token_id' => 42,
            'user_id' => $user->id,
        ]);
    }

    public function test_log_inserts_row_with_null_user_when_unauthenticated(): void
    {
        $request = Request::create('http://test/api/sessions/token-lookup?physical_id=Z99&qr_hash=xyz', 'GET');

        $this->service->log($request, null, 'not_found', null, null);

        $this->assertDatabaseHas('triage_scan_log', [
            'physical_id' => 'Z99',
            'qr_hash' => 'xyz',
            'result' => 'not_found',
            'token_id' => null,
            'user_id' => null,
        ]);
    }

    public function test_log_uses_passed_physical_id_and_qr_hash_over_request_query(): void
    {
        $request = Request::create('http://test/api/sessions/token-lookup?physical_id=Q&qr_hash=Q', 'GET');

        $this->service->log($request, 1, 'deactivated', 'A1', 'hash-from-token');

        $this->assertDatabaseHas('triage_scan_log', [
            'physical_id' => 'A1',
            'qr_hash' => 'hash-from-token',
            'result' => 'deactivated',
            'token_id' => 1,
        ]);
    }
}
