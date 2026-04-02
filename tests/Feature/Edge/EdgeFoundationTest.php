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
}
