<?php

namespace Tests\Feature\Edge;

use App\Models\EdgeDevice;
use App\Models\EdgePairingCode;
use App\Models\Site;
use App\Services\EdgePairingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class EdgePairingTest extends TestCase
{
    use RefreshDatabase;

    private function createSite(int $maxDevices = 5): Site
    {
        return Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site-' . Str::random(4),
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => ['max_edge_devices' => $maxDevices],
            'edge_settings' => [],
        ]);
    }

    public function test_can_generate_code_returns_true_when_under_limit(): void
    {
        $site = $this->createSite(maxDevices: 3);
        $service = app(EdgePairingService::class);

        $this->assertTrue($service->canGenerateCode($site->id));
    }

    public function test_can_generate_code_returns_false_when_limit_zero(): void
    {
        $site = $this->createSite(maxDevices: 0);
        $service = app(EdgePairingService::class);

        $this->assertFalse($service->canGenerateCode($site->id));
    }

    public function test_can_generate_code_returns_false_when_at_limit(): void
    {
        $site = $this->createSite(maxDevices: 1);
        EdgeDevice::create([
            'site_id' => $site->id,
            'name' => 'Existing Device',
            'device_token_hash' => hash('sha256', Str::random(64)),
            'id_offset' => 10_000_000,
            'sync_mode' => 'auto',
            'supervisor_admin_access' => false,
            'session_active' => false,
            'update_status' => 'up_to_date',
            'paired_at' => now(),
        ]);
        $service = app(EdgePairingService::class);

        $this->assertFalse($service->canGenerateCode($site->id));
    }

    public function test_generate_code_returns_eight_char_uppercase_string(): void
    {
        $site = $this->createSite();
        $service = app(EdgePairingService::class);

        $code = $service->generateCode($site->id, 'Field Pi 1');

        $this->assertSame(8, strlen($code));
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $code);
    }

    public function test_generate_code_creates_pairing_code_record(): void
    {
        $site = $this->createSite();
        $service = app(EdgePairingService::class);

        $plain = $service->generateCode($site->id, 'Field Pi 1');

        $this->assertDatabaseHas('edge_pairing_codes', [
            'site_id' => $site->id,
            'code_hash' => hash('sha256', $plain),
            'device_name' => 'Field Pi 1',
        ]);
    }

    public function test_validate_and_consume_creates_edge_device(): void
    {
        $site = $this->createSite();
        $service = app(EdgePairingService::class);
        $plain = $service->generateCode($site->id, 'Field Pi 1');

        $result = $service->validateAndConsume($plain);

        $this->assertArrayHasKey('device_token', $result);
        $this->assertArrayHasKey('device_id', $result);
        $this->assertArrayHasKey('site_id', $result);
        $this->assertArrayHasKey('site_name', $result);
        $this->assertArrayHasKey('id_offset', $result);
        $this->assertSame($site->id, $result['site_id']);
        $this->assertSame(10_000_000, $result['id_offset']);
    }

    public function test_validate_and_consume_marks_code_consumed(): void
    {
        $site = $this->createSite();
        $service = app(EdgePairingService::class);
        $plain = $service->generateCode($site->id, 'Field Pi 1');

        $service->validateAndConsume($plain);

        $code = EdgePairingCode::where('code_hash', hash('sha256', $plain))->first();
        $this->assertNotNull($code->consumed_at);
    }

    public function test_validate_and_consume_throws_on_invalid_code(): void
    {
        $service = app(EdgePairingService::class);

        $this->expectException(\InvalidArgumentException::class);
        $service->validateAndConsume('BADCODE1');
    }

    public function test_validate_and_consume_throws_on_consumed_code(): void
    {
        $site = $this->createSite();
        $service = app(EdgePairingService::class);
        $plain = $service->generateCode($site->id, 'Field Pi 1');
        $service->validateAndConsume($plain);

        $this->expectException(\InvalidArgumentException::class);
        $service->validateAndConsume($plain);
    }

    public function test_validate_and_consume_throws_on_expired_code(): void
    {
        $site = $this->createSite();
        EdgePairingCode::create([
            'site_id' => $site->id,
            'code_hash' => hash('sha256', 'EXPIR3D1'),
            'device_name' => 'Old Pi',
            'expires_at' => now()->subMinute(),
            'created_at' => now()->subMinutes(11),
        ]);
        $service = app(EdgePairingService::class);

        $this->expectException(\InvalidArgumentException::class);
        $service->validateAndConsume('EXPIR3D1');
    }

    public function test_id_offset_increments_per_device(): void
    {
        $site = $this->createSite(maxDevices: 5);
        $service = app(EdgePairingService::class);

        $plain1 = $service->generateCode($site->id, 'Pi 1');
        $result1 = $service->validateAndConsume($plain1);

        $plain2 = $service->generateCode($site->id, 'Pi 2');
        $result2 = $service->validateAndConsume($plain2);

        $this->assertSame(10_000_000, $result1['id_offset']);
        $this->assertSame(20_000_000, $result2['id_offset']);
    }
}
