<?php

namespace Tests\Unit\Jobs;

use App\Jobs\GenerateStationTtsJob;
use App\Models\Process;
use App\Models\Program;
use App\Models\Station;
use App\Models\User;
use App\Services\DisplayBoardService;
use App\Services\TtsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class GenerateStationTtsJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_exits_early_when_tts_disabled(): void
    {
        $program = Program::create([
            'name' => 'P',
            'description' => null,
            'is_active' => false,
            'created_by' => User::factory()->admin()->create()->id,
        ]);
        $process = Process::create(['program_id' => $program->id, 'name' => 'V', 'description' => null]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'S1',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $station->processes()->attach($process->id);

        $ttsService = Mockery::mock(TtsService::class);
        $ttsService->shouldReceive('isEnabled')->once()->andReturn(false);
        $this->app->instance(TtsService::class, $ttsService);

        $job = new GenerateStationTtsJob($station);
        $job->handle(
            $this->app->make(TtsService::class),
            $this->app->make(DisplayBoardService::class)
        );

        $station->refresh();
        $this->assertEmpty($station->settings['tts']['languages']['en']['status'] ?? null);
    }

    public function test_handle_exits_early_when_station_has_no_program(): void
    {
        $program = Program::create([
            'name' => 'P',
            'description' => null,
            'is_active' => false,
            'created_by' => User::factory()->admin()->create()->id,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'S1',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $program->delete();
        $station->refresh();

        $ttsService = Mockery::mock(TtsService::class);
        $ttsService->shouldReceive('isEnabled')->once()->andReturn(true);
        $this->app->instance(TtsService::class, $ttsService);

        $job = new GenerateStationTtsJob($station);
        $job->handle(
            $this->app->make(TtsService::class),
            $this->app->make(DisplayBoardService::class)
        );

        $this->assertTrue(true);
    }
}
