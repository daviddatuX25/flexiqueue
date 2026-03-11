<?php

namespace Tests\Unit\Services;

use App\Models\Program;
use App\Models\Station;
use App\Models\User;
use App\Services\DisplayBoardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Display board payload: queueing method fields (per flexiqueue-syam).
 */
class DisplayBoardServiceTest extends TestCase
{
    use RefreshDatabase;

    private DisplayBoardService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DisplayBoardService::class);
    }

    public function test_get_board_data_returns_null_queueing_method_when_no_program(): void
    {
        $data = $this->service->getBoardData();

        $this->assertArrayHasKey('balance_mode', $data);
        $this->assertArrayHasKey('station_selection_mode', $data);
        $this->assertArrayHasKey('queueing_method_label', $data);
        $this->assertArrayHasKey('queue_mode_display', $data);
        $this->assertArrayHasKey('alternate_ratio', $data);
        $this->assertNull($data['balance_mode']);
        $this->assertNull($data['station_selection_mode']);
        $this->assertNull($data['queueing_method_label']);
        $this->assertNull($data['queue_mode_display']);
        $this->assertNull($data['alternate_ratio']);
        $this->assertArrayHasKey('priority_first', $data);
        $this->assertNull($data['priority_first']);
    }

    public function test_get_board_data_includes_queueing_method_label_for_fifo(): void
    {
        $user = User::factory()->create();
        Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'settings' => ['balance_mode' => 'fifo', 'station_selection_mode' => 'fixed'],
        ]);

        $data = $this->service->getBoardData();

        $this->assertSame('fifo', $data['balance_mode']);
        $this->assertSame('fixed', $data['station_selection_mode']);
        $this->assertSame('FIFO', $data['queueing_method_label']);
        $this->assertSame('FIFO', $data['queue_mode_display']);
        $this->assertNull($data['alternate_ratio']);
    }

    public function test_get_board_data_includes_queueing_method_label_for_alternate(): void
    {
        $user = User::factory()->create();
        Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'settings' => ['balance_mode' => 'alternate', 'station_selection_mode' => 'fixed', 'alternate_ratio' => [2, 1]],
        ]);

        $data = $this->service->getBoardData();

        $this->assertSame('alternate', $data['balance_mode']);
        $this->assertSame('Balanced (alternate)', $data['queueing_method_label']);
        $this->assertSame('Alternate (2 : 1)', $data['queue_mode_display']);
        $this->assertSame([2, 1], $data['alternate_ratio']);
    }

    public function test_get_board_data_includes_combined_label_when_station_selection_not_fixed(): void
    {
        $user = User::factory()->create();
        Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'settings' => ['balance_mode' => 'fifo', 'station_selection_mode' => 'shortest_queue'],
        ]);

        $data = $this->service->getBoardData();

        $this->assertSame('shortest_queue', $data['station_selection_mode']);
        $this->assertSame('FIFO · Shortest queue', $data['queueing_method_label']);
    }

    public function test_get_station_board_data_includes_queueing_method_fields(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'settings' => ['balance_mode' => 'alternate', 'station_selection_mode' => 'least_busy', 'alternate_ratio' => [3, 2]],
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'Window 1',
            'capacity' => 1,
            'is_active' => true,
        ]);

        $data = $this->service->getStationBoardData($station);

        $this->assertSame('alternate', $data['balance_mode']);
        $this->assertSame('least_busy', $data['station_selection_mode']);
        $this->assertSame('Balanced (alternate) · Least busy', $data['queueing_method_label']);
        $this->assertSame('Alternate (3 : 2)', $data['queue_mode_display']);
        $this->assertSame([3, 2], $data['alternate_ratio']);
        $this->assertTrue($data['priority_first']);
    }

    public function test_get_station_board_data_includes_priority_first_when_alternate(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'settings' => [
                'balance_mode' => 'alternate',
                'alternate_ratio' => [2, 1],
                'priority_first' => false,
            ],
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'Window 1',
            'capacity' => 1,
            'is_active' => true,
        ]);

        $data = $this->service->getStationBoardData($station);

        $this->assertFalse($data['priority_first']);
    }
}
