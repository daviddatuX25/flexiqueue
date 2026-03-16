<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Program;
use App\Models\Site;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'api_key_hash' => \Illuminate\Support\Facades\Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $this->admin = User::factory()->admin()->create(['site_id' => $site->id]);
        $this->staff = User::factory()->create(['role' => 'staff', 'site_id' => $site->id, 'assigned_station_id' => null]);
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
            'email' => 'new@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'staff',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('user.name', 'New Staff');
        $response->assertJsonPath('user.email', 'new@example.com');
        $response->assertJsonPath('user.role', 'staff');
        $response->assertJsonPath('user.is_active', true);
        $this->assertDatabaseHas('users', ['email' => 'new@example.com']);
        $user = User::where('email', 'new@example.com')->first();
        $this->assertNotNull($user->override_pin, 'New user should have default preset PIN');
        $this->assertNotNull($user->override_qr_token, 'New user should have default preset QR token');
    }

    public function test_store_with_override_pin_creates_user(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/admin/users', [
            'name' => 'Supervisor Staff',
            'email' => 'super@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'staff',
            'override_pin' => '123456',
        ]);

        $response->assertStatus(201);
        $user = User::where('email', 'super@example.com')->first();
        $this->assertNotNull($user->override_pin);
    }

    public function test_store_validates_email_unique(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/admin/users', [
            'name' => 'Duplicate',
            'email' => $this->staff->email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'staff',
        ]);

        $response->assertStatus(422);
    }

    public function test_update_modifies_user(): void
    {
        $response = $this->actingAs($this->admin)->putJson("/api/admin/users/{$this->staff->id}", [
            'name' => 'Updated Name',
            'email' => $this->staff->email,
            'role' => 'staff',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('user.name', 'Updated Name');
        $this->staff->refresh();
        $this->assertSame('Updated Name', $this->staff->name);
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
        ]);

        $response->assertStatus(200);
        $this->staff->refresh();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('newpassword123', $this->staff->password));
    }

    public function test_non_admin_cannot_store_user(): void
    {
        $response = $this->actingAs($this->staff)->postJson('/api/admin/users', [
            'name' => 'New',
            'email' => 'new@example.com',
            'password' => 'password123',
            'role' => 'staff',
        ]);

        $response->assertStatus(403);
    }
}
