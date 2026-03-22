<?php

namespace Tests\Feature\Api;

use App\Models\Process;
use App\Models\Program;
use App\Models\ServiceTrack;
use App\Models\Site;
use App\Models\Station;
use App\Models\TrackStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class StaffTriageBindContextTest extends TestCase
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

    public function test_staff_gets_triage_bind_context_json(): void
    {
        $site = $this->defaultSite();
        $staff = User::factory()->create(['role' => 'staff', 'site_id' => $site->id]);
        $program = Program::create([
            'site_id' => $site->id,
            'name' => 'Ctx Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $staff->id,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'S1',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $staff->update(['assigned_station_id' => $station->id]);
        $track = ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Default',
            'is_default' => true,
            'color_code' => '#333',
        ]);
        $process = Process::create(['program_id' => $program->id, 'name' => 'P', 'description' => null]);
        TrackStep::create([
            'track_id' => $track->id,
            'process_id' => $process->id,
            'step_order' => 1,
            'is_required' => true,
        ]);

        $response = $this->actingAs($staff)->getJson('/api/staff/triage-bind-context');

        $response->assertOk();
        $response->assertJsonPath('id', $program->id);
        $response->assertJsonPath('name', 'Ctx Program');
        $this->assertNotEmpty($response->json('tracks'));
        $response->assertJsonPath('identity_binding_mode', 'disabled');
        $response->assertJsonPath('show_staff_client_category', true);
    }

    public function test_triage_bind_context_hides_staff_client_category_when_identity_binding_required(): void
    {
        $site = $this->defaultSite();
        $staff = User::factory()->create(['role' => 'staff', 'site_id' => $site->id]);
        $program = Program::create([
            'site_id' => $site->id,
            'name' => 'Required Bind Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $staff->id,
            'settings' => ['identity_binding_mode' => 'required'],
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'S1',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $staff->update(['assigned_station_id' => $station->id]);
        $track = ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Default',
            'is_default' => true,
            'color_code' => '#333',
        ]);
        $process = Process::create(['program_id' => $program->id, 'name' => 'P', 'description' => null]);
        TrackStep::create([
            'track_id' => $track->id,
            'process_id' => $process->id,
            'step_order' => 1,
            'is_required' => true,
        ]);

        $response = $this->actingAs($staff)->getJson('/api/staff/triage-bind-context');

        $response->assertOk();
        $response->assertJsonPath('identity_binding_mode', 'required');
        $response->assertJsonPath('show_staff_client_category', false);
    }
}
