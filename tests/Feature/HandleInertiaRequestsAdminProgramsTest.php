<?php

namespace Tests\Feature;

use App\Models\DeviceAuthorization;
use App\Models\Program;
use App\Models\Site;
use App\Models\Station;
use App\Models\User;
use App\Services\DeviceAuthorizationService;
use App\Support\DeviceLock;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
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
            'api_key_hash' => Hash::make(Str::random(40)),
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
        $site = Site::create([
            'name' => 'Default',
            'slug' => 'default',
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $admin = User::factory()->admin()->create(['site_id' => $site->id]);
        $staff = User::factory()->create(['role' => 'staff', 'site_id' => $site->id]);
        $program = Program::create([
            'site_id' => $site->id,
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
            'api_key_hash' => Hash::make(Str::random(40)),
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
            'api_key_hash' => Hash::make(Str::random(40)),
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

    /** Per A.4.1 / A.4.4: admin dashboard receives first active program as currentProgram (StatusFooter chip). Per B.4: programs site-scoped. */
    public function test_admin_dashboard_receives_current_program_null_and_programs(): void
    {
        $site = Site::create([
            'name' => 'Default',
            'slug' => 'default',
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $admin = User::factory()->admin()->create(['site_id' => $site->id]);
        $program = Program::create([
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
            ->where('currentProgram.id', $program->id)
            ->where('currentProgram.name', 'Alpha')
            ->has('programs')
        );
    }

    /** Per A.4.1 / A.4.4: staff on station receives shared currentProgram only. */
    public function test_station_page_shared_data_has_current_program(): void
    {
        $site = Site::create([
            'name' => 'Default',
            'slug' => 'default',
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $admin = User::factory()->admin()->create(['site_id' => $site->id]);
        $staff = User::factory()->create(['role' => 'staff', 'site_id' => $site->id]);
        $program = Program::create([
            'site_id' => $site->id,
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
            ->has('currentProgram')
            ->where('currentProgram.id', $program->id)
            ->where('currentProgram.name', 'Station Program')
        );
    }

    /** Per A.4.1 / A.4.4: triage page receives shared currentProgram when staff has assigned station. */
    public function test_triage_page_shared_data_has_current_program(): void
    {
        $site = Site::create([
            'name' => 'Default',
            'slug' => 'default',
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $admin = User::factory()->admin()->create(['site_id' => $site->id]);
        $staff = User::factory()->create(['role' => 'staff', 'site_id' => $site->id]);
        $program = Program::create([
            'site_id' => $site->id,
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

    /** Per public-site plan: known_sites cookie required for /site/* routes. */
    private function withKnownSiteCookie(Site $site): static
    {
        $value = json_encode([['slug' => $site->slug, 'name' => $site->name]]);

        return $this->withUnencryptedCookie('known_sites', $value);
    }

    /** Per A.4.1 / A.4.4: display board (unauthenticated) with ?program= on per-site URL receives shared currentProgram. */
    public function test_display_board_with_program_query_receives_shared_current_program(): void
    {
        $site = Site::create([
            'name' => 'Default',
            'slug' => 'default',
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $admin = User::factory()->admin()->create(['site_id' => $site->id]);
        $program = Program::create([
            'site_id' => $site->id,
            'name' => 'Display Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        $service = app(DeviceAuthorizationService::class);
        $auth = $service->authorize($program, 'test-device-'.$program->id, DeviceAuthorization::SCOPE_SESSION);
        $cookieName = DeviceAuthorizationService::cookieNameForProgram($program);
        $lockCookie = DeviceLock::encode($site->slug, $program->slug, DeviceLock::TYPE_DISPLAY, null);

        $response = $this->withKnownSiteCookie($site)
            ->withCookie($cookieName, $auth['cookie_value'])
            ->withUnencryptedCookie(DeviceLock::COOKIE_NAME, $lockCookie->getValue())
            ->get('/site/'.$site->slug.'/display?program='.$program->id);

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->has('currentProgram')
            ->where('currentProgram.id', $program->id)
            ->where('currentProgram.name', 'Display Program')
        );
    }

    /** Per A.4.1 / A.4.4: display board without program param on per-site URL has null currentProgram in shared data. */
    public function test_display_board_without_program_param_has_null_current_program(): void
    {
        $site = Site::firstOrCreate(
            ['slug' => 'default'],
            ['name' => 'Default', 'api_key_hash' => Hash::make(Str::random(40)), 'settings' => [], 'edge_settings' => []]
        );

        $response = $this->withKnownSiteCookie($site)->get('/site/'.$site->slug.'/display');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->has('currentProgram')
            ->where('currentProgram', null)
        );
    }

    /** Phase 5: shared auth.can mirrors Spatie; staff receive public_device_authorize from seeded role. */
    public function test_station_page_includes_auth_can_public_device_authorize_true_for_staff(): void
    {
        $site = Site::create([
            'name' => 'Default',
            'slug' => 'default',
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $admin = User::factory()->admin()->create(['site_id' => $site->id]);
        $staff = User::factory()->create(['role' => 'staff', 'site_id' => $site->id]);
        $program = Program::create([
            'site_id' => $site->id,
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
            ->where('auth.can.public_device_authorize', true)
            ->where('auth.can.public_display_settings_apply', true)
        );
    }

    /**
     * Without public.device.authorize, shared props report device_locked on an allowed display URL
     * (EnforceDeviceLock would 302 /station while a display lock is active — test on /site/.../display?program=).
     */
    public function test_staff_without_public_device_authorize_gets_device_locked_when_lock_in_session(): void
    {
        Role::findByName('staff', PermissionCatalog::guardName())
            ?->revokePermissionTo(PermissionCatalog::PUBLIC_DEVICE_AUTHORIZE);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $site = Site::create([
            'name' => 'Default',
            'slug' => 'default',
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $admin = User::factory()->admin()->create(['site_id' => $site->id]);
        $staff = User::factory()->create(['role' => 'staff', 'site_id' => $site->id]);
        $program = Program::create([
            'site_id' => $site->id,
            'name' => 'Display Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        Station::create([
            'program_id' => $program->id,
            'name' => 'Desk One',
            'capacity' => 1,
            'is_active' => true,
        ]);

        $knownSites = json_encode([['slug' => $site->slug, 'name' => $site->name]]);

        $response = $this->withUnencryptedCookie('known_sites', $knownSites)
            ->actingAs($staff)
            ->withSession([
                DeviceLock::SESSION_KEY => [
                    'site_slug' => $site->slug,
                    'program_slug' => $program->slug,
                    'device_type' => DeviceLock::TYPE_DISPLAY,
                ],
            ])
            ->get('/site/'.$site->slug.'/display?program='.$program->id);

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('auth.can.public_device_authorize', false)
            ->where('device_locked', true)
        );
    }
}
