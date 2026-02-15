<?php

namespace Tests\Unit\Services;

use App\Models\Program;
use App\Models\User;
use App\Services\StationQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StationQueueServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_program_footer_stats_returns_zeros_when_no_program(): void
    {
        $service = app(StationQueueService::class);

        $result = $service->getProgramFooterStats(null);

        $this->assertSame(0, $result['queue_count']);
        $this->assertSame(0, $result['processed_today']);
    }

    public function test_get_program_footer_stats_returns_zeros_when_program_has_no_sessions(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Test Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $service = app(StationQueueService::class);

        $result = $service->getProgramFooterStats($program);

        $this->assertSame(0, $result['queue_count']);
        $this->assertSame(0, $result['processed_today']);
    }
}
