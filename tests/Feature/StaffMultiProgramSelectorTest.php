<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\ProgramStationAssignment;
use App\Models\ServiceTrack;
use App\Models\Site;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class StaffMultiProgramSelectorTest extends TestCase
{
    use RefreshDatabase;

    private function defaultSite(): Site
    {
        return Site::firstOrCreate(
            ['slug' => 'default'],
            [
                'name' => 'Default',
                'api_key_hash' => Hash::make(Str::random(40)),
                'settings' => [],
                'edge_settings' => [],
            ]
        );
    }

    public function test_staff_with_single_program_does_not_get_program_selector_on_station(): void
    {
        $site = $this->defaultSite();
        $admin = User::factory()->admin()->create(['site_id' => $site->id]);
        $staff = User::factory()->create(['site_id' => $site->id]);

        $program = Program::create([
            'site_id' => $site->id,
            'name' => 'Program One',
            'description' => null,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'Desk A',
            'capacity' => 1,
            'is_active' => true,
        ]);

        $staff->update(['assigned_station_id' => $station->id]);

        $response = $this->actingAs($staff)->get(route('station'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Station/Index')
            ->where('canSwitchProgram', false)
            ->where('programs', [])
        );
    }

    public function test_staff_with_multiple_active_program_assignments_gets_program_selector_shared_across_pages_when_not_pinned(): void
    {
        $site = $this->defaultSite();
        $admin = User::factory()->admin()->create(['site_id' => $site->id]);
        $staff = User::factory()->create(['site_id' => $site->id]);

        $programA = Program::create([
            'site_id' => $site->id,
            'name' => 'Program A',
            'description' => null,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        $programB = Program::create([
            'site_id' => $site->id,
            'name' => 'Program B',
            'description' => null,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $stationA = Station::create([
            'program_id' => $programA->id,
            'name' => 'Desk A',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $stationB = Station::create([
            'program_id' => $programB->id,
            'name' => 'Desk B',
            'capacity' => 1,
            'is_active' => true,
        ]);

        // Staff can work in both programs via per-program assignments; no single pinned assigned_station_id.
        ProgramStationAssignment::create([
            'program_id' => $programA->id,
            'user_id' => $staff->id,
            'station_id' => $stationA->id,
        ]);
        ProgramStationAssignment::create([
            'program_id' => $programB->id,
            'user_id' => $staff->id,
            'station_id' => $stationB->id,
        ]);

        // Choose Program B via /station?program=... (same mechanism as admin/supervisor).
        $selectResponse = $this->actingAs($staff)->get('/station?program='.$programB->id);
        $selectResponse->assertRedirect('/station');
        $this->assertEquals(
            $programB->id,
            $selectResponse->getSession()->get('staff_selected_program_id')
        );

        // Station page uses selected program and exposes selector metadata.
        $stationResponse = $this->actingAs($staff)->get(route('station'));
        $stationResponse->assertStatus(200);
        $stationResponse->assertInertia(fn ($page) => $page
            ->component('Station/Index')
            ->where('currentProgram.id', $programB->id)
            ->where('canSwitchProgram', true)
            ->has('programs', 2)
        );

        // Triage uses the same shared program selection when staff has no pinned assigned_station_id.
        ServiceTrack::create([
            'program_id' => $programA->id,
            'name' => 'Default A',
            'is_default' => true,
            'color_code' => '#333',
        ]);
        ServiceTrack::create([
            'program_id' => $programB->id,
            'name' => 'Default B',
            'is_default' => true,
            'color_code' => '#333',
        ]);

        $triageResponse = $this->actingAs($staff)->get(route('client-registration'));
        $triageResponse->assertStatus(200);
        $triageResponse->assertInertia(fn ($page) => $page
            ->component('Triage/Index')
            ->where('activeProgram.id', $programB->id)
            ->where('canSwitchProgram', true)
            ->has('programs', 2)
        );

        // Program Overrides uses the same shared program selection for this staff user.
        $overridesResponse = $this->actingAs($staff)->get('/track-overrides');
        $overridesResponse->assertStatus(200);
        $overridesResponse->assertInertia(fn ($page) => $page
            ->component('ProgramOverrides/Index')
            ->where('currentProgram.id', $programB->id)
            ->where('canSwitchProgram', true)
            ->has('programs', 2)
        );
    }

    public function test_staff_with_multiple_active_program_assignments_and_pinned_station_still_gets_selector(): void
    {
        $site = $this->defaultSite();
        $admin = User::factory()->admin()->create(['site_id' => $site->id]);
        $staff = User::factory()->create(['site_id' => $site->id]);

        $programA = Program::create([
            'site_id' => $site->id,
            'name' => 'Program A',
            'description' => null,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        $programB = Program::create([
            'site_id' => $site->id,
            'name' => 'Program B',
            'description' => null,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $stationA = Station::create([
            'program_id' => $programA->id,
            'name' => 'Desk A',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $stationB = Station::create([
            'program_id' => $programB->id,
            'name' => 'Desk B',
            'capacity' => 1,
            'is_active' => true,
        ]);

        // Staff can work in both programs via assignments and also has a pinned station (legacy behavior).
        ProgramStationAssignment::create([
            'program_id' => $programA->id,
            'user_id' => $staff->id,
            'station_id' => $stationA->id,
        ]);
        ProgramStationAssignment::create([
            'program_id' => $programB->id,
            'user_id' => $staff->id,
            'station_id' => $stationB->id,
        ]);
        $staff->update(['assigned_station_id' => $stationA->id]);

        // Choose Program B via /station?program=...; selector should still be available.
        $selectResponse = $this->actingAs($staff)->get('/station?program='.$programB->id);
        $selectResponse->assertRedirect('/station');
        $this->assertEquals(
            $programB->id,
            $selectResponse->getSession()->get('staff_selected_program_id')
        );

        $stationResponse = $this->actingAs($staff)->get(route('station'));
        $stationResponse->assertStatus(200);
        $stationResponse->assertInertia(fn ($page) => $page
            ->component('Station/Index')
            ->where('currentProgram.id', $programB->id)
            ->where('canSwitchProgram', true)
            ->has('programs', 2)
        );
    }

    public function test_staff_with_pinned_station_and_multi_program_assignments_shares_program_selection_across_station_triage_and_overrides(): void
    {
        $site = $this->defaultSite();
        $admin = User::factory()->admin()->create(['site_id' => $site->id]);
        $staff = User::factory()->create(['site_id' => $site->id]);

        $programA = Program::create([
            'site_id' => $site->id,
            'name' => 'Program A',
            'description' => null,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        $programB = Program::create([
            'site_id' => $site->id,
            'name' => 'Program B',
            'description' => null,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $stationA = Station::create([
            'program_id' => $programA->id,
            'name' => 'Desk A',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $stationB = Station::create([
            'program_id' => $programB->id,
            'name' => 'Desk B',
            'capacity' => 1,
            'is_active' => true,
        ]);

        // Staff can work in both programs and has a pinned assigned_station_id for Program A.
        ProgramStationAssignment::create([
            'program_id' => $programA->id,
            'user_id' => $staff->id,
            'station_id' => $stationA->id,
        ]);
        ProgramStationAssignment::create([
            'program_id' => $programB->id,
            'user_id' => $staff->id,
            'station_id' => $stationB->id,
        ]);
        $staff->update(['assigned_station_id' => $stationA->id]);

        // Service tracks so Triage has a valid activeProgram payload.
        ServiceTrack::create([
            'program_id' => $programA->id,
            'name' => 'Default A',
            'is_default' => true,
            'color_code' => '#333',
        ]);
        ServiceTrack::create([
            'program_id' => $programB->id,
            'name' => 'Default B',
            'is_default' => true,
            'color_code' => '#333',
        ]);

        // 1) Staff selects Program B via /station?program=...
        $selectResponse = $this->actingAs($staff)->get('/station?program='.$programB->id);
        $selectResponse->assertRedirect('/station');
        $this->assertEquals(
            $programB->id,
            $selectResponse->getSession()->get('staff_selected_program_id')
        );

        // 2) Station uses Program B and exposes selector metadata.
        $stationResponse = $this->actingAs($staff)->get(route('station'));
        $stationResponse->assertStatus(200);
        $stationResponse->assertInertia(fn ($page) => $page
            ->component('Station/Index')
            ->where('currentProgram.id', $programB->id)
            ->where('canSwitchProgram', true)
            ->has('programs', 2)
        );

        // 3) Triage uses the same shared program selection.
        $triageResponse = $this->actingAs($staff)->get(route('client-registration'));
        $triageResponse->assertStatus(200);
        $triageResponse->assertInertia(fn ($page) => $page
            ->component('Triage/Index')
            ->where('activeProgram.id', $programB->id)
            ->where('canSwitchProgram', true)
            ->has('programs', 2)
        );

        // 4) Program Overrides also uses the shared selection.
        $overridesResponse = $this->actingAs($staff)->get('/track-overrides');
        $overridesResponse->assertStatus(200);
        $overridesResponse->assertInertia(fn ($page) => $page
            ->component('ProgramOverrides/Index')
            ->where('currentProgram.id', $programB->id)
            ->where('canSwitchProgram', true)
            ->has('programs', 2)
        );
    }
}
