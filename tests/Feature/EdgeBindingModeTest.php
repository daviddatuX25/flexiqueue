<?php

namespace Tests\Feature;

use App\Models\IdentityRegistration;
use App\Models\Process;
use App\Models\Program;
use App\Models\ServiceTrack;
use App\Models\Site;
use App\Models\Station;
use App\Models\TrackStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Per docs/final-edge-mode-rush-plann.md [DF-23]: Triage identity_binding_mode and identity-registrations blocked when edge.
 */
class EdgeBindingModeTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Program $programRequired;

    private Program $programDisabled;

    private User $staff;

    private Station $station;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'api_key_hash' => \Illuminate\Support\Facades\Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $admin = User::factory()->admin()->create(['site_id' => $this->site->id]);
        $this->programRequired = Program::create([
            'site_id' => $this->site->id,
            'name' => 'Required Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $admin->id,
            'settings' => ['identity_binding_mode' => 'required'],
        ]);
        $this->programDisabled = Program::create([
            'site_id' => $this->site->id,
            'name' => 'Disabled Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $admin->id,
            'settings' => ['identity_binding_mode' => 'disabled'],
        ]);
        $this->station = Station::create([
            'program_id' => $this->programRequired->id,
            'name' => 'S1',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $process = Process::create(['program_id' => $this->programRequired->id, 'name' => 'P1', 'description' => null]);
        \Illuminate\Support\Facades\DB::table('station_process')->insert([
            'station_id' => $this->station->id,
            'process_id' => $process->id,
        ]);
        $track = ServiceTrack::create([
            'program_id' => $this->programRequired->id,
            'name' => 'Default',
            'is_default' => true,
            'color_code' => null,
        ]);
        TrackStep::create([
            'track_id' => $track->id,
            'station_id' => $this->station->id,
            'process_id' => $process->id,
            'step_order' => 1,
            'is_required' => true,
        ]);
        $this->staff = User::factory()->create([
            'role' => 'staff',
            'site_id' => $this->site->id,
            'assigned_station_id' => $this->station->id,
        ]);
    }

    public function test_triage_passes_identity_binding_mode_optional_when_program_required_and_app_mode_edge(): void
    {
        config(['app.mode' => 'edge']);

        $response = $this->actingAs($this->staff)->get(route('triage'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Triage/Index')
            ->where('activeProgram.identity_binding_mode', 'optional')
        );
    }

    public function test_triage_passes_identity_binding_mode_required_when_app_mode_central(): void
    {
        config(['app.mode' => 'central']);

        $response = $this->actingAs($this->staff)->get(route('triage'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Triage/Index')
            ->where('activeProgram.identity_binding_mode', 'required')
        );
    }

    public function test_triage_passes_identity_binding_mode_disabled_unchanged_in_both_modes(): void
    {
        $this->staff->update(['assigned_station_id' => null]);
        $stationDisabled = Station::create([
            'program_id' => $this->programDisabled->id,
            'name' => 'S2',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $this->staff->update(['assigned_station_id' => $stationDisabled->id]);
        $process = Process::create(['program_id' => $this->programDisabled->id, 'name' => 'P2', 'description' => null]);
        \Illuminate\Support\Facades\DB::table('station_process')->insert([
            'station_id' => $stationDisabled->id,
            'process_id' => $process->id,
        ]);
        $track = ServiceTrack::create([
            'program_id' => $this->programDisabled->id,
            'name' => 'Default',
            'is_default' => true,
            'color_code' => null,
        ]);
        TrackStep::create([
            'track_id' => $track->id,
            'station_id' => $stationDisabled->id,
            'process_id' => $process->id,
            'step_order' => 1,
            'is_required' => true,
        ]);

        config(['app.mode' => 'edge']);
        $responseEdge = $this->actingAs($this->staff)->get(route('triage'));
        $responseEdge->assertStatus(200);
        $responseEdge->assertInertia(fn ($page) => $page->where('activeProgram.identity_binding_mode', 'disabled'));

        config(['app.mode' => 'central']);
        $responseCentral = $this->actingAs($this->staff)->get(route('triage'));
        $responseCentral->assertStatus(200);
        $responseCentral->assertInertia(fn ($page) => $page->where('activeProgram.identity_binding_mode', 'disabled'));
    }

    public function test_post_identity_registrations_direct_returns_403_when_app_mode_edge(): void
    {
        config(['app.mode' => 'edge']);

        $response = $this->actingAs($this->staff)->postJson('/api/identity-registrations/direct', [
            'first_name' => 'Direct',
            'last_name' => 'Blocked',
            'birth_date' => '1990-01-01',
            'client_category' => 'Regular',
        ]);

        $response->assertStatus(403);
    }

    public function test_post_identity_registrations_accept_returns_403_when_app_mode_edge(): void
    {
        config(['app.mode' => 'edge']);
        $reg = IdentityRegistration::create([
            'program_id' => $this->programRequired->id,
            'status' => 'pending',
            'first_name' => 'Accept',
            'last_name' => 'Blocked',
            'birth_date' => '1985-01-01',
        ]);

        $response = $this->actingAs($this->staff)->postJson("/api/identity-registrations/{$reg->id}/accept", [
            'client_id' => null,
            'create_client' => true,
        ]);

        $response->assertStatus(403);
    }

    public function test_post_identity_registrations_confirm_bind_returns_403_when_app_mode_edge(): void
    {
        config(['app.mode' => 'edge']);
        $reg = IdentityRegistration::create([
            'program_id' => $this->programRequired->id,
            'status' => 'pending',
            'request_type' => 'bind_confirmation',
            'first_name' => 'Bind',
            'last_name' => 'Blocked',
            'birth_date' => '1980-01-01',
        ]);

        $response = $this->actingAs($this->staff)->postJson("/api/identity-registrations/{$reg->id}/confirm-bind", []);

        $response->assertStatus(403);
    }

    public function test_get_identity_registrations_returns_200_when_app_mode_edge(): void
    {
        config(['app.mode' => 'edge']);

        $response = $this->actingAs($this->staff)->getJson('/api/identity-registrations');

        $response->assertStatus(200);
    }
}
