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
}
