<?php

namespace Tests\Feature\Api;

use App\Models\Program;
use App\Models\ServiceTrack;
use App\Models\Session;
use App\Models\Station;
use App\Models\Token;
use App\Models\TrackStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Per 08-API-SPEC-PHASE1 §6.1: Dashboard stats and stations API. Auth: role:admin,supervisor.
 */
class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $supervisor;

    private User $staff;

    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->supervisor = User::factory()->supervisor()->create();
        $this->staff = User::factory()->create(['role' => 'staff']);
        $this->program = Program::create([
            'name' => 'Cash Assistance',
            'description' => null,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        $station = Station::create([
            'program_id' => $this->program->id,
            'name' => 'Interview',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $track = ServiceTrack::create([
            'program_id' => $this->program->id,
            'name' => 'Regular',
            'is_default' => true,
            'color_code' => '#333',
        ]);
        TrackStep::create([
            'track_id' => $track->id,
            'station_id' => $station->id,
            'step_order' => 1,
            'is_required' => true,
        ]);
        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).'A1');
        $token->physical_id = 'A1';
        $token->status = 'in_use';
        $token->save();
        Session::create([
            'token_id' => $token->id,
            'program_id' => $this->program->id,
            'track_id' => $track->id,
            'alias' => 'A1',
            'client_category' => 'regular',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'status' => 'waiting',
        ]);
        $token->update(['current_session_id' => Session::first()->id]);

        $this->program->supervisedBy()->attach($this->supervisor->id);
    }

    public function test_stats_returns_200_for_admin(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/dashboard/stats');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'active_program' => ['id', 'name'],
            'sessions' => [
                'active',
                'waiting',
                'serving',
                'completed_today',
                'cancelled_today',
                'no_show_today',
            ],
            'stations' => ['total', 'active', 'with_queue'],
            'staff_online',
            'by_track',
        ]);
        $response->assertJsonPath('active_program.name', 'Cash Assistance');
        $response->assertJsonPath('sessions.active', 1);
        $response->assertJsonPath('sessions.waiting', 1);
        $response->assertJsonPath('stations.total', 1);
    }

    public function test_stats_returns_200_for_supervisor(): void
    {
        $response = $this->actingAs($this->supervisor)->getJson('/api/dashboard/stats');

        $response->assertStatus(200);
        $response->assertJsonPath('active_program.name', 'Cash Assistance');
    }

    public function test_stats_returns_403_for_staff(): void
    {
        $response = $this->actingAs($this->staff)->getJson('/api/dashboard/stats');

        $response->assertStatus(403);
    }

    public function test_stats_returns_null_program_when_no_active_program(): void
    {
        $this->program->update(['is_active' => false]);

        $response = $this->actingAs($this->admin)->getJson('/api/dashboard/stats');

        $response->assertStatus(200);
        $response->assertJsonPath('active_program', null);
        $response->assertJsonPath('sessions.active', 0);
        $response->assertJsonPath('stations.total', 0);
    }

    public function test_stations_returns_200_for_admin(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/dashboard/stations');

        $response->assertStatus(200);
        $response->assertJsonStructure(['stations' => [['id', 'name', 'is_active', 'queue_count', 'current_client', 'assigned_staff']]]);
        $response->assertJsonPath('stations.0.name', 'Interview');
        $response->assertJsonPath('stations.0.queue_count', 1);
    }

    public function test_stations_returns_403_for_staff(): void
    {
        $response = $this->actingAs($this->staff)->getJson('/api/dashboard/stations');

        $response->assertStatus(403);
    }
}
