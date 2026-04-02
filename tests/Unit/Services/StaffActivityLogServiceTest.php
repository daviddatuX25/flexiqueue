<?php

namespace Tests\Unit\Services;

use App\Services\StaffActivityLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StaffActivityLogServiceTest extends TestCase
{
    use RefreshDatabase;

    private StaffActivityLogService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new StaffActivityLogService();
    }

    public function test_logActivity_inserts_row_when_table_exists(): void
    {
        $this->service->logActivity(42, 'availability_change', 'offline', 'available');

        $this->assertDatabaseHas('staff_activity_log', [
            'user_id'     => 42,
            'action_type' => 'availability_change',
            'old_value'   => 'offline',
            'new_value'   => 'available',
            'metadata'    => null,
        ]);
    }

    public function test_logActivity_encodes_metadata_as_json(): void
    {
        $this->service->logActivity(7, 'availability_change', 'on_break', 'available', ['reason' => 'lunch']);

        $row = DB::table('staff_activity_log')
            ->where('user_id', 7)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('{"reason":"lunch"}', $row->metadata);
    }

    public function test_logActivity_does_nothing_when_table_does_not_exist(): void
    {
        Schema::shouldReceive('hasTable')->with('staff_activity_log')->andReturn(false);

        // No exception, no DB write
        $this->service->logActivity(1, 'availability_change', 'offline', 'available');

        $this->assertDatabaseCount('staff_activity_log', 0);
    }
}
