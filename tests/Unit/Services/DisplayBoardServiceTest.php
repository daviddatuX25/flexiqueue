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
        $data = $this->service->getBoardData(null);

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
        $this->assertNull($data['program_name']);
        $this->assertSame([], $data['now_serving']);
        $this->assertSame(0, $data['total_in_queue']);
        $this->assertArrayHasKey('prefer_generated_audio', $data);
        $this->assertArrayHasKey('segment_2_enabled', $data);
        $this->assertArrayHasKey('tts_closing_without_segment2', $data);
    }

    /** A.2.4: getBoardData(programId) returns data scoped to that program. */
    public function test_get_board_data_with_program_id_returns_data_for_that_program(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Scoped Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'settings' => ['balance_mode' => 'fifo', 'station_selection_mode' => 'fixed'],
        ]);

        $data = $this->service->getBoardData($program->id);

        $this->assertSame('Scoped Program', $data['program_name']);
        $this->assertSame('fifo', $data['balance_mode']);
        $this->assertSame('FIFO', $data['queueing_method_label']);
        $this->assertIsArray($data['now_serving']);
        $this->assertIsArray($data['waiting_by_station']);
    }

    /** A.2.4: getBoardData(null) returns no-program structure (program_name null, empty arrays). */
    public function test_get_board_data_with_null_returns_no_program_structure(): void
    {
        $data = $this->service->getBoardData(null);

        $this->assertNull($data['program_name']);
        $this->assertSame([], $data['now_serving']);
        $this->assertSame([], $data['waiting_by_station']);
        $this->assertSame(0, $data['total_in_queue']);
    }

    public function test_get_board_data_includes_queueing_method_label_for_fifo(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'settings' => ['balance_mode' => 'fifo', 'station_selection_mode' => 'fixed'],
        ]);

        $data = $this->service->getBoardData($program->id);

        $this->assertSame('fifo', $data['balance_mode']);
        $this->assertSame('fixed', $data['station_selection_mode']);
        $this->assertSame('FIFO', $data['queueing_method_label']);
        $this->assertSame('FIFO', $data['queue_mode_display']);
        $this->assertNull($data['alternate_ratio']);
    }

    public function test_get_board_data_includes_queueing_method_label_for_alternate(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'settings' => ['balance_mode' => 'alternate', 'station_selection_mode' => 'fixed', 'alternate_ratio' => [2, 1]],
        ]);

        $data = $this->service->getBoardData($program->id);

        $this->assertSame('alternate', $data['balance_mode']);
        $this->assertSame('Balanced (alternate)', $data['queueing_method_label']);
        $this->assertSame('Alternate (2 : 1)', $data['queue_mode_display']);
        $this->assertSame([2, 1], $data['alternate_ratio']);
    }

    public function test_get_board_data_includes_combined_label_when_station_selection_not_fixed(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'settings' => ['balance_mode' => 'fifo', 'station_selection_mode' => 'shortest_queue'],
        ]);

        $data = $this->service->getBoardData($program->id);

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

        $this->assertArrayHasKey('can_update_station_display_settings', $data);
        $this->assertFalse($data['can_update_station_display_settings']);
        $this->assertSame(1.0, $data['display_page_zoom']);

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
