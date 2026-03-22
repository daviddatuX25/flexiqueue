<?php

namespace Tests\Feature\Api;

use App\Models\Program;
use App\Models\ServiceTrack;
use App\Models\Session;
use App\Models\Site;
use App\Models\Station;
use App\Models\Token;
use App\Models\TrackStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $this->admin = User::factory()->admin()->create(['site_id' => $this->site->id]);
        $this->supervisor = User::factory()->supervisor()->create(['site_id' => $this->site->id]);
        $this->staff = User::factory()->create(['role' => 'staff', 'site_id' => $this->site->id]);
        $this->program = Program::create([
            'site_id' => $this->site->id,
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
        $response = $this->actingAs($this->admin)->getJson('/api/dashboard/stats?program_id='.$this->program->id);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'active_program' => ['id', 'name'],
            'active_programs_count',
            'program' => ['is_active', 'is_paused', 'is_running'],
            'sessions' => [
                'active',
                'waiting',
                'serving',
                'completed_today',
                'cancelled_today',
                'no_show_today',
            ],
            'stations' => ['total', 'active', 'with_queue'],
            'stations_online',
            'staff_online',
            'by_track',
        ]);
        $response->assertJsonPath('active_program.name', 'Cash Assistance');
        $response->assertJsonPath('active_programs_count', 1);
        $response->assertJsonPath('program.is_running', true);
        $response->assertJsonPath('sessions.active', 1);
        $response->assertJsonPath('sessions.waiting', 1);
        $response->assertJsonPath('stations.total', 1);
    }

    public function test_stats_returns_200_for_supervisor(): void
    {
        $response = $this->actingAs($this->supervisor)->getJson('/api/dashboard/stats?program_id='.$this->program->id);

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

        $response = $this->actingAs($this->admin)->getJson('/api/dashboard/stats?program_id='.$this->program->id);

        $response->assertStatus(200);
        $response->assertJsonPath('active_program', null);
        $response->assertJsonPath('active_programs_count', 0);
        $response->assertJsonPath('program.is_running', false);
        $response->assertJsonPath('sessions.active', 0);
        $response->assertJsonPath('stations.total', 0);
    }

    public function test_stats_active_programs_count_counts_only_activated_programs_on_viewer_site(): void
    {
        Program::create([
            'site_id' => $this->site->id,
            'name' => 'Second Active',
            'description' => null,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        Program::create([
            'site_id' => $this->site->id,
            'name' => 'Inactive Program',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/dashboard/stats?program_id='.$this->program->id);
        $response->assertStatus(200);
        $response->assertJsonPath('active_programs_count', 2);
    }

    public function test_stats_queue_waiting_includes_held_but_excludes_called_and_serving(): void
    {
        $station = Station::where('program_id', $this->program->id)->firstOrFail();
        $track = ServiceTrack::where('program_id', $this->program->id)->firstOrFail();
        $token = Token::query()->firstOrFail();

        Session::create([
            'token_id' => $token->id,
            'program_id' => $this->program->id,
            'track_id' => $track->id,
            'alias' => 'A2',
            'client_category' => 'regular',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'status' => 'called',
        ]);

        Session::create([
            'token_id' => $token->id,
            'program_id' => $this->program->id,
            'track_id' => $track->id,
            'alias' => 'A3',
            'client_category' => 'regular',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'status' => 'serving',
        ]);

        Session::create([
            'token_id' => $token->id,
            'program_id' => $this->program->id,
            'track_id' => $track->id,
            'alias' => 'A4',
            'client_category' => 'regular',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'status' => 'serving',
            'is_on_hold' => true,
            'held_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/dashboard/stats?program_id='.$this->program->id);
        $response->assertStatus(200);

        // From setup(): 1 waiting. From this test: held session counts as waiting, called/serving do not.
        $response->assertJsonPath('sessions.waiting', 2);
    }

    public function test_stats_stations_online_counts_distinct_stations_with_available_assigned_staff(): void
    {
        $station = Station::where('program_id', $this->program->id)->firstOrFail();
        $this->admin->update([
            'assigned_station_id' => $station->id,
            'availability_status' => 'available',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/dashboard/stats?program_id='.$this->program->id);
        $response->assertStatus(200);
        $response->assertJsonPath('stations_online', 1);
    }

    public function test_stations_returns_200_for_admin(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/dashboard/stations?program_id='.$this->program->id);

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
