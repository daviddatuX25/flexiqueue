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
            'settings' => [],
            'edge_settings' => ['max_edge_devices' => $maxDevices],
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

    // --- AuthenticateEdgeDevice middleware (will pass after Task 3 routes exist) ---

    public function test_edge_api_rejects_missing_token(): void
    {
        $response = $this->getJson('/api/edge/assignment');
        $response->assertStatus(401);
    }

    public function test_edge_api_rejects_invalid_token(): void
    {
        $response = $this->withToken('invalid_token')->getJson('/api/edge/assignment');
        $response->assertStatus(401);
    }

    // --- POST /api/edge/pair ---

    public function test_pair_endpoint_returns_device_token_for_valid_code(): void
    {
        $site    = $this->createSite();
        $service = app(\App\Services\EdgePairingService::class);
        $plain   = $service->generateCode($site->id, 'Field Pi 1');

        $response = $this->postJson('/api/edge/pair', ['pairing_code' => $plain]);

        $response->assertStatus(200)
                 ->assertJsonStructure(['device_token', 'device_id', 'site_id', 'site_name', 'id_offset']);
    }

    public function test_pair_endpoint_rejects_invalid_code(): void
    {
        $response = $this->postJson('/api/edge/pair', ['pairing_code' => 'BADCODE1']);
        $response->assertStatus(422)->assertJsonPath('error', 'Invalid or expired pairing code.');
    }

    public function test_pair_endpoint_rejects_short_code(): void
    {
        $response = $this->postJson('/api/edge/pair', ['pairing_code' => 'SHORT']);
        $response->assertStatus(422);
    }

    // --- Helper ---

    private function createPairedDevice(\App\Models\Site $site, ?int $assignedProgramId = null): array
    {
        $plain  = \Illuminate\Support\Str::random(64);
        $device = \App\Models\EdgeDevice::create([
            'site_id'                 => $site->id,
            'name'                    => 'Test Pi',
            'device_token_hash'       => hash('sha256', $plain),
            'id_offset'               => 10_000_000,
            'sync_mode'               => 'auto',
            'supervisor_admin_access' => false,
            'session_active'          => false,
            'update_status'           => 'up_to_date',
            'paired_at'               => now(),
            'assigned_program_id'     => $assignedProgramId,
        ]);
        return ['device' => $device, 'token' => $plain];
    }

    // --- GET /api/edge/assignment ---

    public function test_assignment_returns_null_when_no_program_assigned(): void
    {
        $site = $this->createSite();
        ['token' => $token] = $this->createPairedDevice($site);

        $response = $this->withToken($token)->getJson('/api/edge/assignment');

        $response->assertStatus(200)
                 ->assertJsonPath('assigned', false)
                 ->assertJsonPath('program', null);
    }

    public function test_assignment_rejects_unknown_token(): void
    {
        $response = $this->withToken('totally_fake')->getJson('/api/edge/assignment');
        $response->assertStatus(401);
    }

    // --- POST /api/edge/heartbeat ---

    public function test_heartbeat_updates_device_last_seen_at(): void
    {
        $site = $this->createSite();
        ['token' => $token, 'device' => $device] = $this->createPairedDevice($site);

        $response = $this->withToken($token)->postJson('/api/edge/heartbeat', [
            'session_active'  => false,
            'sync_mode'       => 'auto',
            'last_synced_at'  => null,
            'package_version' => null,
            'app_version'     => '1.0.0',
        ]);

        $response->assertStatus(200)->assertJsonPath('revoked', false);
        $this->assertNotNull($device->fresh()->last_seen_at);
    }

    public function test_heartbeat_returns_revoked_true_for_revoked_device(): void
    {
        $site = $this->createSite();
        ['token' => $token, 'device' => $device] = $this->createPairedDevice($site);
        $device->update(['revoked_at' => now()]);

        $response = $this->withToken($token)->postJson('/api/edge/heartbeat', [
            'session_active'  => false,
            'sync_mode'       => 'auto',
            'last_synced_at'  => null,
            'package_version' => null,
            'app_version'     => null,
        ]);

        $response->assertStatus(200)->assertJsonPath('revoked', true);
    }

    // --- EdgeDeviceSetupService ---

    public function test_setup_service_writes_edge_device_state_after_pairing(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            '*/api/edge/pair' => \Illuminate\Support\Facades\Http::response([
                'device_token' => 'tok_abc123',
                'device_id'    => 1,
                'site_id'      => 99,
                'site_name'    => 'Demo Site',
                'id_offset'    => 10_000_000,
            ], 200),
        ]);

        $service = new \App\Services\EdgeDeviceSetupService(writeEnv: false);
        $service->setup('https://central.test', 'ABCD1234', 'auto');

        $state = \App\Models\EdgeDeviceState::current();
        $this->assertSame('https://central.test', $state->central_url);
        $this->assertSame('auto', $state->sync_mode);
        $this->assertSame(10_000_000, $state->id_offset);
        $this->assertNotNull($state->paired_at);
    }

    public function test_setup_service_throws_on_pairing_failure(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            '*/api/edge/pair' => \Illuminate\Support\Facades\Http::response(
                ['error' => 'Invalid or expired pairing code.'], 422
            ),
        ]);

        $service = new \App\Services\EdgeDeviceSetupService(writeEnv: false);

        $this->expectException(\RuntimeException::class);
        $service->setup('https://central.test', 'BADCODE1', 'auto');
    }

    // --- edge:heartbeat command ---

    public function test_heartbeat_command_sends_heartbeat_and_updates_state(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'https://central.test/api/edge/heartbeat' => \Illuminate\Support\Facades\Http::response([
                'revoked'                 => false,
                'sync_mode'               => 'end_of_event',
                'supervisor_admin_access' => true,
                'update_available'        => false,
            ], 200),
        ]);

        \App\Models\EdgeDeviceState::updateOrCreate(
            ['id' => 1],
            [
                'central_url'             => 'https://central.test',
                'device_token'            => 'fake_device_token_for_test',
                'site_id'                 => 1,
                'site_name'               => 'Test Site',
                'id_offset'               => 10_000_000,
                'sync_mode'               => 'auto',
                'paired_at'               => now(),
                'session_active'          => false,
                'supervisor_admin_access' => false,
            ]
        );

        config(['app.mode' => 'edge']);

        $this->artisan('edge:heartbeat')->assertSuccessful();

        $state = \App\Models\EdgeDeviceState::current();
        $this->assertSame('end_of_event', $state->sync_mode);
        $this->assertTrue($state->supervisor_admin_access);
    }

    public function test_heartbeat_command_skips_when_not_paired(): void
    {
        config(['app.mode' => 'edge']);
        \App\Models\EdgeDeviceState::updateOrCreate(
            ['id' => 1],
            ['paired_at' => null, 'device_token' => null]
        );

        $this->artisan('edge:heartbeat')->assertSuccessful();

        \Illuminate\Support\Facades\Http::assertNothingSent();
    }
}
