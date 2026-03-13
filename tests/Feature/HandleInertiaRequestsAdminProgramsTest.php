<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\Site;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Per central-edge A.2.5: Admin receives programs (all active) in shared Inertia props;
 * no single activeProgram for admin. Staff station/triage get currentProgram from controller.
 */
class HandleInertiaRequestsAdminProgramsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_visiting_admin_dashboard_receives_programs_and_no_single_active_program(): void
    {
        $site = Site::create([
            'name' => 'Default',
            'slug' => 'default',
            'api_key_hash' => \Illuminate\Support\Facades\Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $admin = User::factory()->admin()->create(['site_id' => $site->id]);
        Program::create([
            'site_id' => $site->id,
            'name' => 'Alpha',
            'description' => null,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        Program::create([
            'site_id' => $site->id,
            'name' => 'Beta',
            'description' => null,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertStatus(200);
        $programs = Program::where('site_id', $site->id)->orderBy('name')->get(['id', 'name']);
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Dashboard')
            ->has('programs')
            ->where('programs.0.id', $programs[0]->id)
            ->where('programs.0.name', 'Alpha')
            ->where('programs.1.id', $programs[1]->id)
            ->where('programs.1.name', 'Beta')
        );
    }

    public function test_staff_visiting_station_page_receives_current_program_from_controller(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create(['role' => 'staff']);
        $program = Program::create([
            'name' => 'Station Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'Desk One',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $staff->update(['assigned_station_id' => $station->id]);

        $response = $this->actingAs($staff)->get(route('station'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Station/Index')
            ->has('currentProgram')
            ->where('currentProgram.id', $program->id)
            ->where('currentProgram.name', 'Station Program')
        );
    }

    public function test_admin_visits_program_show_has_current_program_and_programs(): void
    {
        $site = Site::create([
            'name' => 'Default',
            'slug' => 'default',
            'api_key_hash' => \Illuminate\Support\Facades\Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $admin = User::factory()->admin()->create(['site_id' => $site->id]);
        $program = Program::create([
            'site_id' => $site->id,
            'name' => 'Show Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.programs.show', $program));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Programs/Show')
            ->has('currentProgram')
            ->where('currentProgram.id', $program->id)
            ->where('currentProgram.name', 'Show Program')
            ->has('programs')
        );
    }

    public function test_admin_visits_dashboard_has_programs_may_have_no_current_program(): void
    {
        $site = Site::create([
            'name' => 'Default',
            'slug' => 'default',
            'api_key_hash' => \Illuminate\Support\Facades\Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $admin = User::factory()->admin()->create(['site_id' => $site->id]);
        Program::create([
            'site_id' => $site->id,
            'name' => 'Only',
            'description' => null,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Dashboard')
            ->has('programs')
            ->has('programs.0')
        );
    }

    /** Per A.4.1 / A.4.4: shared data includes currentProgram only; admin gets null. Per B.4: programs site-scoped. */
    public function test_admin_dashboard_receives_current_program_null_and_programs(): void
    {
        $site = Site::create([
            'name' => 'Default',
            'slug' => 'default',
            'api_key_hash' => \Illuminate\Support\Facades\Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $admin = User::factory()->admin()->create(['site_id' => $site->id]);
        Program::create([
            'site_id' => $site->id,
            'name' => 'Alpha',
            'description' => null,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->has('currentProgram')
            ->where('currentProgram', null)
            ->has('programs')
        );
    }

    /** Per A.4.1 / A.4.4: staff on station receives shared currentProgram only. */
    public function test_station_page_shared_data_has_current_program(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create(['role' => 'staff']);
        $program = Program::create([
            'name' => 'Station Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'Desk One',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $staff->update(['assigned_station_id' => $station->id]);

        $response = $this->actingAs($staff)->get(route('station', ['station' => $station]));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->has('currentProgram')
            ->where('currentProgram.id', $program->id)
            ->where('currentProgram.name', 'Station Program')
        );
    }

    /** Per A.4.1 / A.4.4: triage page receives shared currentProgram when staff has assigned station. */
    public function test_triage_page_shared_data_has_current_program(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create(['role' => 'staff']);
        $program = Program::create([
            'name' => 'Triage Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'Desk One',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $staff->update(['assigned_station_id' => $station->id]);

        $response = $this->actingAs($staff)->get(route('triage'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->has('currentProgram')
            ->where('currentProgram.id', $program->id)
        );
    }

    /** Per A.4.1 / A.4.4: display board (unauthenticated) with ?program= receives shared currentProgram. */
    public function test_display_board_with_program_query_receives_shared_current_program(): void
    {
        $admin = User::factory()->admin()->create();
        $program = Program::create([
            'name' => 'Display Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $response = $this->get(route('display').'?program='.$program->id);

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->has('currentProgram')
            ->where('currentProgram.id', $program->id)
            ->where('currentProgram.name', 'Display Program')
        );
    }

    /** Per A.4.1 / A.4.4: display board without program param has null currentProgram in shared data. */
    public function test_display_board_without_program_param_has_null_current_program(): void
    {
        $response = $this->get(route('display'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->has('currentProgram')
            ->where('currentProgram', null)
        );
    }
}
