<?php

namespace Tests\Feature\Edge;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
