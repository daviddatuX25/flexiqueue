<?php

namespace Tests\Feature\Api\Admin;

use App\Models\AdminActionLog;
use App\Models\Program;
use App\Models\Site;
use App\Models\Station;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Per 08-API-SPEC-PHASE1 §5.6, §5.7: User CRUD and staff assignment.
 */
class UserControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staff;

    private Station $station1;

    private Station $station2;

    protected function setUp(): void
    {
        parent::setUp();
        $site = Site::create([
            'name' => 'Default Site',
            'slug' => 'default',
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $this->admin = User::factory()->admin()->create(['site_id' => $site->id]);
        $this->staff = User::factory()->create(['site_id' => $site->id, 'assigned_station_id' => null]);
        $program = Program::create([
            'site_id' => $site->id,
            'name' => 'Test Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        $this->station1 = Station::create([
            'program_id' => $program->id,
            'name' => 'Verification',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $this->station2 = Station::create([
            'program_id' => $program->id,
            'name' => 'Interview',
            'capacity' => 1,
            'is_active' => true,
        ]);
    }

    public function test_assign_station_returns_200(): void
    {
        $response = $this->actingAs($this->admin)->postJson("/api/admin/users/{$this->staff->id}/assign-station", [
            'station_id' => $this->station1->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('user.assigned_station_id', $this->station1->id);
        $response->assertJsonPath('user.assigned_station.name', 'Verification');
        $this->assertDatabaseHas('users', ['id' => $this->staff->id, 'assigned_station_id' => $this->station1->id]);
    }

    public function test_unassign_station_returns_200(): void
    {
        $this->staff->update(['assigned_station_id' => $this->station1->id]);

        $response = $this->actingAs($this->admin)->postJson("/api/admin/users/{$this->staff->id}/unassign-station");

        $response->assertStatus(200);
        $response->assertJsonPath('user.assigned_station_id', null);
        $this->assertDatabaseHas('users', ['id' => $this->staff->id, 'assigned_station_id' => null]);
    }

    public function test_index_returns_users_with_stations(): void
    {
        $this->staff->update(['assigned_station_id' => $this->station1->id]);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/users');

        $response->assertStatus(200);
        $response->assertJsonStructure(['users']);
    }

    public function test_non_admin_cannot_assign_station(): void
    {
        $response = $this->actingAs($this->staff)->postJson("/api/admin/users/{$this->staff->id}/assign-station", [
            'station_id' => $this->station1->id,
        ]);

        $response->assertStatus(403);
    }

    public function test_store_creates_user_returns_201(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/admin/users', [
            'name' => 'New Staff',
            'username' => 'new.staff',
            'email' => 'new@example.com',
            'recovery_gmail' => 'new.recovery@gmail.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'staff',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('user.name', 'New Staff');
        $response->assertJsonPath('user.username', 'new.staff');
        $response->assertJsonPath('user.email', 'new@example.com');
        $response->assertJsonPath('user.recovery_gmail', 'new.recovery@gmail.com');
        $response->assertJsonPath('user.role', 'staff');
        $response->assertJsonPath('user.is_active', true);
        $this->assertDatabaseHas('users', ['email' => 'new@example.com', 'username' => 'new.staff']);
        $user = User::where('email', 'new@example.com')->first();
        $this->assertNotNull($user->override_pin, 'New user should have default preset PIN');
        $this->assertNotNull($user->override_qr_token, 'New user should have default preset QR token');
    }

    public function test_store_with_override_pin_creates_user(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/admin/users', [
            'name' => 'Supervisor Staff',
            'username' => 'super.staff',
            'email' => 'super@example.com',
            'recovery_gmail' => 'super.recovery@gmail.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'staff',
            'override_pin' => '123456',
        ]);

        $response->assertStatus(201);
        $user = User::where('email', 'super@example.com')->first();
        $this->assertNotNull($user->override_pin);
    }

    public function test_store_staff_with_pending_assignment_sets_flag(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/admin/users', [
            'name' => 'Pending Staff',
            'username' => 'pending.staff',
            'email' => 'pending@example.com',
            'recovery_gmail' => 'pending.recovery@gmail.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'staff',
            'pending_assignment' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('user.pending_assignment', true);
        $this->assertDatabaseHas('users', [
            'email' => 'pending@example.com',
            'pending_assignment' => true,
        ]);
    }

    public function test_store_validates_email_unique(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/admin/users', [
            'name' => 'Duplicate',
            'username' => 'duplicate.user',
            'email' => $this->staff->email,
            'recovery_gmail' => 'dup.recovery@gmail.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422);
    }

    public function test_update_modifies_user(): void
    {
        $response = $this->actingAs($this->admin)->putJson("/api/admin/users/{$this->staff->id}", [
            'name' => 'Updated Name',
            'username' => $this->staff->username,
            'email' => $this->staff->email,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('user.name', 'Updated Name');
        $this->staff->refresh();
        $this->assertSame('Updated Name', $this->staff->name);
    }

    public function test_admin_cannot_change_own_is_active_via_api(): void
    {
        $this->assertTrue($this->admin->is_active);

        $response = $this->actingAs($this->admin)->putJson("/api/admin/users/{$this->admin->id}", [
            'is_active' => false,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['is_active']);
        $this->admin->refresh();
        $this->assertTrue($this->admin->is_active);
    }

    public function test_destroy_deactivates_user(): void
    {
        $response = $this->actingAs($this->admin)->deleteJson("/api/admin/users/{$this->staff->id}");

        $response->assertStatus(200);
        $this->staff->refresh();
        $this->assertFalse($this->staff->is_active);
    }

    public function test_reset_password_updates_password(): void
    {
        $response = $this->actingAs($this->admin)->postJson("/api/admin/users/{$this->staff->id}/reset-password", [
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(200);
        $this->staff->refresh();
        $this->assertTrue(Hash::check('newpassword123', $this->staff->password));
        $this->assertDatabaseHas('admin_action_log', [
            'user_id' => $this->admin->id,
            'action' => 'user_password_reset_by_admin',
            'subject_id' => $this->staff->id,
        ]);
        $log = AdminActionLog::query()
            ->where('action', 'user_password_reset_by_admin')
            ->where('subject_id', $this->staff->id)
            ->first();
        $this->assertNotNull($log);
        $this->assertSame($this->staff->username, $log->payload['target_username'] ?? null);
    }

    public function test_reset_password_requires_confirmation(): void
    {
        $response = $this->actingAs($this->admin)->postJson("/api/admin/users/{$this->staff->id}/reset-password", [
            'password' => 'newpassword123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['password']);
    }

    public function test_admin_cannot_reset_own_password_via_reset_endpoint(): void
    {
        $response = $this->actingAs($this->admin)->postJson("/api/admin/users/{$this->admin->id}/reset-password", [
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(403);
    }

    public function test_non_admin_cannot_store_user(): void
    {
        $response = $this->actingAs($this->staff)->postJson('/api/admin/users', [
            'name' => 'New',
            'email' => 'new@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(403);
    }

    public function test_update_direct_permissions_syncs_for_staff(): void
    {
        $response = $this->actingAs($this->admin)->putJson("/api/admin/users/{$this->staff->id}", [
            'name' => $this->staff->name,
            'username' => $this->staff->username,
            'email' => $this->staff->email,
            'direct_permissions' => [PermissionCatalog::DASHBOARD_VIEW],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('user.direct_permissions', [PermissionCatalog::DASHBOARD_VIEW]);
        $this->staff->refresh();
        $this->assertTrue($this->staff->hasDirectPermission(PermissionCatalog::DASHBOARD_VIEW));
    }

    public function test_site_admin_cannot_assign_platform_manage_via_direct_permissions(): void
    {
        $response = $this->actingAs($this->admin)->putJson("/api/admin/users/{$this->staff->id}", [
            'name' => $this->staff->name,
            'username' => $this->staff->username,
            'email' => $this->staff->email,
            'direct_permissions' => [PermissionCatalog::PLATFORM_MANAGE],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['direct_permissions']);
    }

    public function test_cannot_demote_last_active_admin_for_site(): void
    {
        $response = $this->actingAs($this->admin)->putJson("/api/admin/users/{$this->admin->id}", [
            'name' => $this->admin->name,
            'username' => $this->admin->username,
            'email' => $this->admin->email,
            'role' => 'staff',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['role']);
    }

    public function test_can_demote_admin_when_another_admin_exists_for_site(): void
    {
        $secondAdmin = User::factory()->admin()->create([
            'site_id' => $this->admin->site_id,
            'email' => 'other-admin-'.Str::random(6).'@example.com',
        ]);

        $response = $this->actingAs($secondAdmin)->putJson("/api/admin/users/{$this->admin->id}", [
            'name' => $this->admin->name,
            'username' => $this->admin->username,
            'email' => $this->admin->email,
            'role' => 'staff',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('user.role', 'staff');
        $this->assertTrue($secondAdmin->fresh()->isAdmin());
    }
}
