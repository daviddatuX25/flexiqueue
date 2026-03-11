<?php

namespace Tests\Unit\Http\Resources;

use App\Http\Resources\SessionResource;
use App\Models\Program;
use App\Models\ServiceTrack;
use App\Models\Session;
use App\Models\Station;
use App\Models\Token;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Unit tests for SessionResource. Per docs/REFACTORING-ISSUE-LIST.md Issue 5.
 */
class SessionResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_returns_canonical_session_array_with_relations(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Test',
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'Station A',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $track = ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Default',
            'is_default' => true,
            'color_code' => '#333',
        ]);
        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).'T1');
        $token->physical_id = 'T1';
        $token->status = 'in_use';
        $token->save();

        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'A1',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'status' => 'serving',
            'no_show_attempts' => 2,
        ]);

        $data = SessionResource::make($session)->resolve();

        $this->assertSame($session->id, $data['id']);
        $this->assertSame('A1', $data['alias']);
        $this->assertSame('serving', $data['status']);
        $this->assertSame(1, $data['current_step_order']);
        $this->assertSame(2, $data['no_show_attempts']);
        $this->assertArrayHasKey('started_at', $data);
        $this->assertArrayHasKey('completed_at', $data);
        $this->assertNotNull($data['current_station']);
        $this->assertSame($station->id, $data['current_station']['id']);
        $this->assertSame('Station A', $data['current_station']['name']);
        $this->assertNotNull($data['track']);
        $this->assertSame($track->id, $data['track']['id']);
        $this->assertSame('Default', $data['track']['name']);
    }

    public function test_resolve_returns_null_current_station_and_track_when_absent(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Test',
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $track = ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Default',
            'is_default' => true,
            'color_code' => '#333',
        ]);
        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).'T2');
        $token->physical_id = 'T2';
        $token->status = 'in_use';
        $token->save();

        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'A2',
            'current_station_id' => null,
            'current_step_order' => null,
            'status' => 'waiting',
        ]);

        $data = SessionResource::make($session)->resolve();

        $this->assertSame($session->id, $data['id']);
        $this->assertSame('A2', $data['alias']);
        $this->assertSame('waiting', $data['status']);
        $this->assertNull($data['current_station']);
        $this->assertNotNull($data['track']);
        $this->assertSame(0, $data['no_show_attempts']);
    }
}
