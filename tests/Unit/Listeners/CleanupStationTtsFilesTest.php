<?php

namespace Tests\Unit\Listeners;

use App\Events\StationDeleted;
use App\Listeners\CleanupStationTtsFiles;
use App\Models\Program;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Unit tests for CleanupStationTtsFiles listener. Per docs/REFACTORING-ISSUE-LIST.md Issues 11–12.
 */
class CleanupStationTtsFilesTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_deletes_station_tts_directory_and_per_language_audio(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'P',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'Desk 1',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $station->update([
            'settings' => [
                'tts' => [
                    'languages' => [
                        'en' => ['audio_path' => 'tts/stations/'.$station->id.'/en.mp3', 'status' => 'ready'],
                    ],
                ],
            ],
        ]);
        $station->refresh();

        $baseDir = 'tts/stations/'.$station->id;
        $enPath = 'tts/stations/'.$station->id.'/en.mp3';
        Storage::put($baseDir.'/en.mp3', 'audio');
        Storage::put($baseDir.'/fil.mp3', 'audio');

        $listener = new CleanupStationTtsFiles;
        $listener->handle(new StationDeleted($station));

        $this->assertFalse(Storage::exists($baseDir));
        $this->assertFalse(Storage::exists($enPath));
    }

    public function test_handle_deletes_audio_paths_from_settings_languages(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'P',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'Desk 1',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $station->update([
            'settings' => [
                'tts' => [
                    'languages' => [
                        'en' => ['audio_path' => 'tts/stations/'.$station->id.'/en.mp3', 'status' => 'ready'],
                        'fil' => ['audio_path' => 'tts/stations/'.$station->id.'/fil.mp3', 'status' => 'ready'],
                    ],
                ],
            ],
        ]);
        $station->refresh();

        Storage::put('tts/stations/'.$station->id.'/en.mp3', 'audio');
        Storage::put('tts/stations/'.$station->id.'/fil.mp3', 'audio');

        $listener = new CleanupStationTtsFiles;
        $listener->handle(new StationDeleted($station));

        $this->assertFalse(Storage::exists('tts/stations/'.$station->id.'/en.mp3'));
        $this->assertFalse(Storage::exists('tts/stations/'.$station->id.'/fil.mp3'));
    }

    public function test_handle_does_not_throw_when_paths_missing_or_empty_settings(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'P',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'Desk 1',
            'capacity' => 1,
            'is_active' => true,
            'settings' => [],
        ]);

        $listener = new CleanupStationTtsFiles;
        $listener->handle(new StationDeleted($station));

        $this->assertFalse(Storage::exists('tts/stations/'.$station->id));
    }
}
