<?php

namespace Tests\Feature\Admin;

use App\Models\Process;
use App\Models\Program;
use App\Models\ServiceTrack;
use App\Models\Session;
use App\Models\Site;
use App\Models\Station;
use App\Models\Token;
use App\Models\TrackStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Program Show page (Inertia). Per bead flexiqueue-8p4: supervisor-override warning
 * is driven by program.settings.require_permission_before_override and supervisors API.
 * Per bead flexiqueue-6qj: tab order is Overview → Processes → Stations → Staff → Track → Settings.
 */
class ProgramShowPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::create([
            'name' => 'Default Site',
            'slug' => 'default',
            'api_key_hash' => \Illuminate\Support\Facades\Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $this->admin = User::factory()->admin()->create(['site_id' => $this->site->id]);
    }

    private function siteId(): int
    {
        return $this->site->id;
    }

    public function test_program_show_page_includes_nav_tabs_in_spec_order(): void
    {
        $program = Program::create([
            'site_id' => $this->siteId(),
            'name' => 'Tab Order',
            'description' => null,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Default',
            'is_default' => true,
            'color_code' => '#333',
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.programs.show', $program));
        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Programs/Show')
            ->has('tab_order')
            ->where('tab_order', ['Overview', 'Processes', 'Stations', 'Staff', 'Track', 'Diagram', 'Settings'])
        );
    }

    public function test_program_show_with_require_override_and_no_supervisors_provides_data_for_warning(): void
    {
        $program = Program::create([
            'site_id' => $this->siteId(),
            'name' => 'No Supervisors',
            'description' => null,
            'is_active' => true,
            'created_by' => $this->admin->id,
            'settings' => ['require_permission_before_override' => true],
        ]);
        ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Default',
            'is_default' => true,
            'color_code' => '#333',
        ]);
        Station::create([
            'program_id' => $program->id,
            'name' => 'S1',
            'capacity' => 1,
            'is_active' => true,
        ]);

        $pageResponse = $this->actingAs($this->admin)->get(route('admin.programs.show', $program));
        $pageResponse->assertStatus(200);
        $pageResponse->assertInertia(fn ($page) => $page
            ->component('Admin/Programs/Show')
            ->where('program.settings.require_permission_before_override', true)
        );

        $supervisorsResponse = $this->actingAs($this->admin)->getJson("/api/admin/programs/{$program->id}/supervisors");
        $supervisorsResponse->assertStatus(200);
        $supervisorsResponse->assertJsonPath('supervisors', []);
    }

    public function test_program_show_with_require_override_and_supervisors_present_no_warning_data(): void
    {
        $staffWithPin = User::factory()->create(['role' => 'staff']);
        $program = Program::create([
            'site_id' => $this->siteId(),
            'name' => 'With Supervisors',
            'description' => null,
            'is_active' => true,
            'created_by' => $this->admin->id,
            'settings' => ['require_permission_before_override' => true],
        ]);
        $program->supervisedBy()->attach($staffWithPin->id);
        ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Default',
            'is_default' => true,
            'color_code' => '#333',
        ]);
        Station::create([
            'program_id' => $program->id,
            'name' => 'S1',
            'capacity' => 1,
            'is_active' => true,
        ]);

        $pageResponse = $this->actingAs($this->admin)->get(route('admin.programs.show', $program));
        $pageResponse->assertStatus(200);
        $pageResponse->assertInertia(fn ($page) => $page
            ->component('Admin/Programs/Show')
            ->where('program.settings.require_permission_before_override', true)
        );

        $supervisorsResponse = $this->actingAs($this->admin)->getJson("/api/admin/programs/{$program->id}/supervisors");
        $supervisorsResponse->assertStatus(200);
        $supervisors = $supervisorsResponse->json('supervisors');
        $this->assertIsArray($supervisors);
        $this->assertCount(1, $supervisors);
        $this->assertSame($staffWithPin->id, $supervisors[0]['id']);
    }

    /** Per bead flexiqueue-5gl: Settings tab receives program.settings including alternate_priority_first when set. */
    public function test_program_show_settings_tab_receives_alternate_priority_first(): void
    {
        $program = Program::create([
            'site_id' => $this->siteId(),
            'name' => 'Alt Ratio',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
            'settings' => [
                'balance_mode' => 'alternate',
                'alternate_ratio' => [2, 1],
                'alternate_priority_first' => false,
            ],
        ]);
        ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Default',
            'is_default' => true,
            'color_code' => '#333',
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.programs.show', $program));
        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Programs/Show')
            ->has('program')
            ->where('program.settings.balance_mode', 'alternate')
            ->where('program.settings.alternate_priority_first', false)
        );
    }

    /** Per bead flexiqueue-nlu: Show page must pass stats.active_sessions so frontend can show warning when queue not empty. */
    public function test_program_show_returns_stats_with_active_sessions(): void
    {
        $program = Program::create([
            'site_id' => $this->siteId(),
            'name' => 'Stats Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Default',
            'is_default' => true,
            'color_code' => '#333',
        ]);
        Station::create([
            'program_id' => $program->id,
            'name' => 'S1',
            'capacity' => 1,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.programs.show', $program));
        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Programs/Show')
            ->has('stats')
            ->where('stats.active_sessions', 0)
            ->where('stats.total_sessions', 0)
        );
    }

    /** Per bead flexiqueue-nlu: when program has active sessions, stats.active_sessions > 0 for warning path. */
    public function test_program_show_returns_active_sessions_count_when_queue_has_sessions(): void
    {
        $program = Program::create([
            'site_id' => $this->siteId(),
            'name' => 'Active Queue',
            'description' => null,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        $track = ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Default',
            'is_default' => true,
            'color_code' => '#333',
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'S1',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $token = new Token();
        $token->qr_code_hash = str_repeat('a', 64);
        $token->physical_id = 'A1';
        $token->status = 'in_use';
        $token->save();
        Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'A1',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'status' => 'waiting',
            'no_show_attempts' => 0,
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.programs.show', $program));
        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Programs/Show')
            ->has('stats')
            ->where('stats.active_sessions', 1)
        );
    }

    /** Per flexiqueue-5l7: tracks include total_estimated_minutes and travel_queue_minutes. */
    public function test_program_show_tracks_include_total_estimated_and_travel_queue_minutes(): void
    {
        $program = Program::create([
            'site_id' => $this->siteId(),
            'name' => 'Time Program',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'Desk',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $process = Process::create([
            'program_id' => $program->id,
            'name' => 'Verify',
            'description' => null,
        ]);
        $station->processes()->attach($process->id);
        $track = ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Default',
            'is_default' => true,
        ]);
        TrackStep::create([
            'track_id' => $track->id,
            'station_id' => $station->id,
            'process_id' => $process->id,
            'step_order' => 1,
            'is_required' => true,
            'estimated_minutes' => 5,
        ]);
        TrackStep::create([
            'track_id' => $track->id,
            'station_id' => $station->id,
            'process_id' => $process->id,
            'step_order' => 2,
            'is_required' => true,
            'estimated_minutes' => 10,
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.programs.show', $program));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Programs/Show')
            ->has('tracks')
            ->where('tracks.0.total_estimated_minutes', 15)
            ->where('tracks.0.travel_queue_minutes', 0)
        );
    }
}
