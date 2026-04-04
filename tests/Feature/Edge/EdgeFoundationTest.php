<?php

namespace Tests\Feature\Edge;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EdgeFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_edge_devices_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('edge_devices'));
    }

    public function test_edge_devices_has_required_columns(): void
    {
        $columns = [
            'id', 'site_id', 'name', 'device_token_hash', 'id_offset',
            'sync_mode', 'supervisor_admin_access', 'assigned_program_id',
            'session_active', 'app_version', 'last_seen_at', 'last_synced_at',
            'paired_at', 'revoked_at', 'force_cancelled_at', 'update_status',
            'created_at', 'updated_at',
        ];
        foreach ($columns as $col) {
            $this->assertTrue(
                Schema::hasColumn('edge_devices', $col),
                "Missing column: {$col}"
            );
        }
    }

    // Task 2 — edge_pairing_codes migration

    public function test_edge_pairing_codes_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('edge_pairing_codes'));
    }

    public function test_edge_pairing_codes_has_required_columns(): void
    {
        $columns = ['id', 'site_id', 'code_hash', 'device_name', 'expires_at', 'consumed_at', 'created_at'];
        foreach ($columns as $col) {
            $this->assertTrue(
                Schema::hasColumn('edge_pairing_codes', $col),
                "Missing column: {$col}"
            );
        }
    }

    // Task 3 — EdgeDevice and EdgePairingCode models

    private function createTestSite(): \App\Models\Site
    {
        return \App\Models\Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site-' . uniqid(),
            'api_key_hash' => Hash::make('test-key'),
            'settings' => [],
            'edge_settings' => [],
        ]);
    }

    public function test_edge_device_model_can_be_created(): void
    {
        $site = $this->createTestSite();
        $device = \App\Models\EdgeDevice::create([
            'site_id' => $site->id,
            'name' => 'Test Pi',
            'device_token_hash' => hash('sha256', 'secret-token'),
            'id_offset' => 10000000,
            'sync_mode' => 'auto',
            'paired_at' => now(),
        ]);

        $this->assertDatabaseHas('edge_devices', ['name' => 'Test Pi']);
        $this->assertInstanceOf(\App\Models\EdgeDevice::class, $device);
    }

    public function test_edge_pairing_code_model_can_be_created(): void
    {
        $site = $this->createTestSite();
        $code = \App\Models\EdgePairingCode::create([
            'site_id' => $site->id,
            'code_hash' => hash('sha256', '123456'),
            'device_name' => 'Office Pi',
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->assertDatabaseHas('edge_pairing_codes', ['device_name' => 'Office Pi']);
        $this->assertNull($code->consumed_at);
    }

    // Task 4 — programs.edge_locked_by_device_id

    public function test_programs_table_has_edge_locked_by_device_id(): void
    {
        $this->assertTrue(Schema::hasColumn('programs', 'edge_locked_by_device_id'));
    }

    // Task 5 — max_edge_devices in EdgeSettingsValidator

    public function test_edge_settings_validator_accepts_max_edge_devices(): void
    {
        $validator = new \App\Validation\EdgeSettingsValidator();
        $result = $validator->validate(['max_edge_devices' => 3]);

        $this->assertSame(3, $result['max_edge_devices']);
    }

    public function test_edge_settings_validator_rejects_negative_max_edge_devices(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $validator = new \App\Validation\EdgeSettingsValidator();
        $validator->validate(['max_edge_devices' => -1]);
    }

    public function test_edge_settings_validator_defaults_max_edge_devices_to_zero(): void
    {
        $validator = new \App\Validation\EdgeSettingsValidator();
        $result = $validator->validate([]);

        $this->assertSame(0, $result['max_edge_devices']);
    }

    // Task 6 — edge_device_state schema update

    public function test_edge_device_state_has_new_columns(): void
    {
        foreach (['id_offset', 'app_version', 'package_version'] as $col) {
            $this->assertTrue(
                Schema::hasColumn('edge_device_state', $col),
                "Missing column: {$col}"
            );
        }
    }

    public function test_edge_device_state_sync_mode_accepts_new_values(): void
    {
        \App\Models\EdgeDeviceState::updateOrCreate(
            ['id' => 1],
            ['sync_mode' => 'auto', 'supervisor_admin_access' => false, 'session_active' => false]
        );
        $this->assertDatabaseHas('edge_device_state', ['sync_mode' => 'auto']);

        \App\Models\EdgeDeviceState::find(1)->update(['sync_mode' => 'end_of_event']);
        $this->assertDatabaseHas('edge_device_state', ['sync_mode' => 'end_of_event']);
    }

    // Task 7 — edge_device_id on session and log tables

    public function test_session_tables_have_edge_device_id(): void
    {
        $tables = [
            'queue_sessions',
            'transaction_logs',
            'clients',
            'identity_registrations',
            'program_audit_log',
            'staff_activity_log',
        ];
        foreach ($tables as $table) {
            $this->assertTrue(
                Schema::hasColumn($table, 'edge_device_id'),
                "Table {$table} missing edge_device_id"
            );
        }
    }

    // Task 8 — edge_sync_queue and edge_sync_conflicts tables

    public function test_edge_sync_queue_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('edge_sync_queue'));
    }

    public function test_edge_sync_queue_has_required_columns(): void
    {
        foreach (['id', 'event_type', 'payload', 'attempts', 'last_attempted_at', 'created_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('edge_sync_queue', $col), "Missing: {$col}");
        }
    }

    public function test_edge_sync_conflicts_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('edge_sync_conflicts'));
    }

    public function test_edge_sync_conflicts_has_required_columns(): void
    {
        foreach (['id', 'edge_device_id', 'table_name', 'record_id', 'conflict_type', 'resolution', 'created_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('edge_sync_conflicts', $col), "Missing: {$col}");
        }
    }

    // Task 9 — GET /api/ping endpoint

    public function test_api_ping_returns_200(): void
    {
        $response = $this->getJson('/api/ping');
        $response->assertStatus(200);
        $response->assertJson(['status' => 'ok']);
    }

    // Task 10 — real isOnline() in EdgeModeService

    public function test_is_online_returns_false_when_not_edge(): void
    {
        config(['app.mode' => 'central']);
        $service = new \App\Services\EdgeModeService();

        $this->assertFalse($service->isOnline());
    }

    public function test_is_online_returns_true_when_edge_and_ping_succeeds(): void
    {
        config(['app.mode' => 'edge']);
        \App\Models\EdgeDeviceState::updateOrCreate(
            ['id' => 1],
            ['central_url' => 'https://central.example.com', 'sync_mode' => 'auto', 'supervisor_admin_access' => false, 'session_active' => false]
        );

        \Illuminate\Support\Facades\Http::fake([
            'https://central.example.com/api/ping' => \Illuminate\Support\Facades\Http::response(['status' => 'ok'], 200),
        ]);
        \Illuminate\Support\Facades\Cache::flush();

        $service = new \App\Services\EdgeModeService();
        $this->assertTrue($service->isOnline());
    }

    public function test_is_online_returns_false_when_edge_and_ping_fails(): void
    {
        config(['app.mode' => 'edge']);
        \App\Models\EdgeDeviceState::updateOrCreate(
            ['id' => 1],
            ['central_url' => 'https://central.example.com', 'sync_mode' => 'auto', 'supervisor_admin_access' => false, 'session_active' => false]
        );

        \Illuminate\Support\Facades\Http::fake([
            'https://central.example.com/api/ping' => \Illuminate\Support\Facades\Http::response([], 500),
        ]);
        \Illuminate\Support\Facades\Cache::flush();

        $service = new \App\Services\EdgeModeService();
        $this->assertFalse($service->isOnline());
    }

    public function test_is_online_caches_result_for_30_seconds(): void
    {
        config(['app.mode' => 'edge']);
        \App\Models\EdgeDeviceState::updateOrCreate(
            ['id' => 1],
            ['central_url' => 'https://central.example.com', 'sync_mode' => 'auto', 'supervisor_admin_access' => false, 'session_active' => false]
        );

        \Illuminate\Support\Facades\Http::fake([
            'https://central.example.com/api/ping' => \Illuminate\Support\Facades\Http::response(['status' => 'ok'], 200),
        ]);
        \Illuminate\Support\Facades\Cache::flush();

        $service = new \App\Services\EdgeModeService();
        $service->isOnline(); // first call — hits HTTP
        $service->isOnline(); // second call — must use cache, not hit HTTP again

        \Illuminate\Support\Facades\Http::assertSentCount(1);
    }

    // Task 11 — BlockOnEdge middleware

    public function test_block_on_edge_middleware_aborts_404_when_edge(): void
    {
        config(['app.mode' => 'edge']);
        $middleware = new \App\Http\Middleware\BlockOnEdge(
            new \App\Services\EdgeModeService()
        );

        $request = \Illuminate\Http\Request::create('/auth/google', 'GET');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $middleware->handle($request, fn ($r) => response('ok'));
    }

    public function test_block_on_edge_middleware_passes_through_on_central(): void
    {
        config(['app.mode' => 'central']);
        $middleware = new \App\Http\Middleware\BlockOnEdge(
            new \App\Services\EdgeModeService()
        );

        $request = \Illuminate\Http\Request::create('/auth/google', 'GET');
        $response = $middleware->handle($request, fn ($r) => response('ok'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_google_oauth_routes_are_blocked_on_edge(): void
    {
        config(['app.mode' => 'edge']);

        // EdgeBootGuard redirects (302) or BlockOnEdge aborts (404) — either way not a successful response
        $statusGoogle = $this->get('/auth/google')->status();
        $statusCallback = $this->get('/auth/google/callback')->status();

        $this->assertNotSame(200, $statusGoogle, '/auth/google should not be accessible on edge');
        $this->assertNotSame(200, $statusCallback, '/auth/google/callback should not be accessible on edge');
    }

    public function test_google_oauth_routes_not_blocked_by_edge_middleware_on_central(): void
    {
        config(['app.mode' => 'central']);
        $middleware = new \App\Http\Middleware\BlockOnEdge(
            new \App\Services\EdgeModeService()
        );

        $request = \Illuminate\Http\Request::create('/auth/google', 'GET');
        $response = $middleware->handle($request, fn ($r) => response('passed'));

        $this->assertSame('passed', $response->getContent(), 'BlockOnEdge should not block on central');
    }
}
