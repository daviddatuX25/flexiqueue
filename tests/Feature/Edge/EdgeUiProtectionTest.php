<?php

namespace Tests\Feature\Edge;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\EdgeDeviceState;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * E9.1 — Write failing tests for EdgeWriteProtection (tests 1-7)
 *
 * These tests verify that the EdgeWriteProtection middleware blocks
 * write operations on the admin UI when running on edge, while still
 * allowing edge-specific operations (program activate, edge import)
 * and read operations.
 */
class EdgeUiProtectionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site-' . Str::random(4),
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $this->admin = User::factory()->admin()->create(['site_id' => $this->site->id]);
    }

    /**
     * Helper: put the application into edge mode and seed a paired device state.
     */
    private function actAsEdge(): void
    {
        $deviceToken = Str::random(64);
        EdgeDeviceState::updateOrCreate(
            ['id' => 1],
            [
                'central_url' => 'https://central.test',
                'device_token' => Crypt::encrypt($deviceToken),
                'site_id' => $this->site->id,
                'site_name' => $this->site->name,
                'id_offset' => 10_000_000,
                'sync_mode' => 'auto',
                'supervisor_admin_access' => false,
                'session_active' => false,
                'paired_at' => now(),
            ]
        );
        config(['app.mode' => 'edge']);
    }

    /**
     * Helper: seed a paired device state with optional field overrides.
     */
    private function seedPairedState(array $overrides = []): void
    {
        $deviceToken = Str::random(64);
        EdgeDeviceState::updateOrCreate(
            ['id' => 1],
            array_merge([
                'central_url' => 'https://central.test',
                'device_token' => Crypt::encrypt($deviceToken),
                'site_id' => $this->site->id,
                'site_name' => $this->site->name,
                'id_offset' => 10_000_000,
                'sync_mode' => 'auto',
                'supervisor_admin_access' => false,
                'session_active' => false,
                'paired_at' => now(),
            ], $overrides)
        );
        config(['app.mode' => 'edge']);
    }

    // ── Tests 1-3: Blocked operations on edge ─────────────────────────────

    public function test_write_protection_blocks_post_to_admin_users_on_edge(): void
    {
        $this->actAsEdge();

        $response = $this->actingAs($this->admin)->postJson('/api/admin/users', [
            'name' => 'New Staff',
            'username' => 'new.staff',
            'email' => 'new@example.com',
            'recovery_gmail' => 'new.recovery@gmail.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'staff',
        ]);

        $response->assertStatus(403);
    }

    public function test_write_protection_blocks_delete_to_admin_users_on_edge(): void
    {
        $this->actAsEdge();
        $staff = User::factory()->create(['site_id' => $this->site->id]);

        $response = $this->actingAs($this->admin)->deleteJson("/api/admin/users/{$staff->id}");

        $response->assertStatus(403);
    }

    public function test_write_protection_blocks_put_to_settings_on_edge(): void
    {
        $this->actAsEdge();

        $response = $this->actingAs($this->admin)->patchJson('/api/admin/site/settings', [
            'site_name' => 'Updated Site Name',
        ]);

        $response->assertStatus(403);
    }

    // ── Tests 4-5: Allowed operations on edge ─────────────────────────────

    public function test_write_protection_allows_program_activate_on_edge(): void
    {
        $this->actAsEdge();

        $program = \App\Models\Program::create([
            'site_id' => $this->site->id,
            'name' => 'Test Program',
            'slug' => 'test-prog-' . Str::random(4),
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->postJson("/api/admin/programs/{$program->id}/activate");

        // Should NOT be blocked by write protection (returns 200 or whatever the controller returns)
        $this->assertNotEquals(403, $response->status());
    }

    public function test_write_protection_allows_edge_import_on_edge(): void
    {
        $this->actAsEdge();

        $response = $this->actingAs($this->admin)->postJson('/api/admin/edge/import', [
            'program_id' => 1,
        ]);

        // Should NOT be blocked by write protection (returns 200 or 409 based on lock file)
        $this->assertNotEquals(403, $response->status());
    }

    // ── Test 6: Read operations still allowed on edge ────────────────────

    public function test_write_protection_does_not_block_get_on_edge(): void
    {
        $this->actAsEdge();

        $response = $this->actingAs($this->admin)->getJson('/api/admin/users');

        // GET should never be blocked by write protection
        $this->assertNotEquals(403, $response->status());
    }

    // ── Test 7: Central mode is unaffected ───────────────────────────────

    public function test_write_protection_is_inactive_on_central(): void
    {
        // Central mode: write operations should NOT be blocked
        config(['app.mode' => 'central']);

        $response = $this->actingAs($this->admin)->postJson('/api/admin/users', [
            'name' => 'New Staff',
            'username' => 'new.staff.on.central',
            'email' => 'new.central@example.com',
            'recovery_gmail' => 'new.central@gmail.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'staff',
        ]);

        // Should succeed (201) or fail for other reasons, but NOT 403
        $this->assertNotEquals(403, $response->status());
    }


    // ── E9.4: EdgeBootGuard revoked redirect ──────────────────────────────

    /** @test */
    public function boot_guard_redirects_to_revoked_when_is_revoked_true(): void
    {
        $this->seedPairedState(['is_revoked' => true]);
        $this->actAsEdge();

        $this->actingAs($this->admin)->get('/admin/dashboard')
            ->assertRedirect('/edge/revoked');
    }

    /** @test */
    public function boot_guard_allows_access_to_revoked_page_when_is_revoked_true(): void
    {
        $this->seedPairedState(['is_revoked' => true]);
        $this->actAsEdge();

        $this->withoutMiddleware(HandleInertiaRequests::class)
            ->withoutMiddleware(\App\Http\Middleware\EnforcePendingAssignment::class)
            ->withoutMiddleware(\App\Http\Middleware\EnforceDeviceLock::class)
            ->withoutMiddleware(\App\Http\Middleware\AddPermissionsPolicy::class)
            ->withoutMiddleware(\App\Http\Middleware\SetGlobalPermissionsTeam::class)
            ->withSession([])
            ->get('/edge/revoked')
            ->assertStatus(200);
    }

    public function test_heartbeat_command_sets_is_revoked_when_central_returns_revoked(): void
    {
        EdgeDeviceState::where('id', 1)->updateOrInsert(
            ['id' => 1],
            [
                'paired_at'    => now(),
                'device_token' => Crypt::encrypt('test-token'),
                'central_url'  => 'http://central.test',
                'sync_mode'    => 'auto',
                'session_active' => false,
                'is_revoked'   => false,
            ]
        );

        Http::fake([
            'http://central.test/api/edge/heartbeat' => Http::response(['revoked' => true], 200),
        ]);

        config(['app.mode' => 'edge']);
        $this->artisan('edge:heartbeat')->assertSuccessful();

        $state = EdgeDeviceState::current();
        $this->assertTrue((bool) $state->is_revoked);
        $this->assertNull($state->paired_at);
        $this->assertNull($state->device_token);
    }

    /** @test */
    public function setup_clears_is_revoked_state(): void
    {
        EdgeDeviceState::where('id', 1)->updateOrInsert(
            ['id' => 1],
            [
                'paired_at'     => now(),
                'device_token'  => Crypt::encrypt('old-token'),
                'central_url'   => 'http://central.test',
                'sync_mode'     => 'auto',
                'session_active' => false,
                'is_revoked'    => true,
            ]
        );

        Http::fake([
            'http://central.test/api/edge/pair' => Http::response([
                'device_token' => 'new-token',
                'site_id'     => $this->site->id,
                'site_name'   => $this->site->name,
                'id_offset'   => 10_000_000,
            ], 200),
        ]);

        // Directly call the service (writeEnv=false to avoid file system side-effects)
        $service = new \App\Services\EdgeDeviceSetupService(writeEnv: false);
        $service->setup('http://central.test', 'PAIR1234', 'auto');

        $this->assertFalse((bool) EdgeDeviceState::current()->is_revoked);
    }
}