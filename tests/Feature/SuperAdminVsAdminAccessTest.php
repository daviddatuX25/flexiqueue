<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Per SUPER-ADMIN-VS-ADMIN-SPEC: super_admin has no access to programs, tokens, analytics;
 * integrations API is super_admin only; site admin can create fellow admin; no user can delete self.
 */
class SuperAdminVsAdminAccessTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $admin;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'api_key_hash' => bcrypt(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $this->admin = User::factory()->create([
            'role' => 'admin',
            'site_id' => $this->site->id,
        ]);
        $this->superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'site_id' => null,
        ]);
    }

    public function test_super_admin_gets_403_on_programs_page(): void
    {
        $response = $this->actingAs($this->superAdmin)->get(route('admin.programs'));

        $response->assertStatus(403);
    }

    public function test_super_admin_gets_403_on_programs_api(): void
    {
        $response = $this->actingAs($this->superAdmin)->getJson('/api/admin/programs');

        $response->assertStatus(403);
    }

    public function test_super_admin_gets_403_on_tokens_page(): void
    {
        $response = $this->actingAs($this->superAdmin)->get(route('admin.tokens'));

        $response->assertStatus(403);
    }

    public function test_super_admin_gets_403_on_analytics_page(): void
    {
        $response = $this->actingAs($this->superAdmin)->get(route('admin.analytics'));

        $response->assertStatus(403);
    }

    public function test_admin_gets_403_on_integrations_api(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/admin/integrations/elevenlabs');

        $response->assertStatus(403);
    }

    public function test_super_admin_can_access_integrations_api(): void
    {
        $response = $this->actingAs($this->superAdmin)->getJson('/api/admin/integrations/elevenlabs');

        $response->assertStatus(200);
    }

    public function test_super_admin_can_access_dashboard_and_sites(): void
    {
        $this->actingAs($this->superAdmin)->get(route('admin.dashboard'))->assertStatus(200);
        $this->actingAs($this->superAdmin)->get(route('admin.sites'))->assertStatus(200);
        $this->actingAs($this->superAdmin)->get(route('admin.users'))->assertStatus(200);
    }

    public function test_user_cannot_delete_self_via_api(): void
    {
        $response = $this->actingAs($this->admin)->deleteJson("/api/admin/users/{$this->admin->id}");

        $response->assertStatus(403);
    }

    public function test_site_admin_can_create_fellow_admin_for_same_site(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/admin/users', [
            'name' => 'Fellow Admin',
            'email' => 'fellow@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'admin',
        ]);

        $response->assertStatus(201);
        $user = User::where('email', 'fellow@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('admin', $user->role->value);
        $this->assertSame($this->site->id, $user->site_id);
    }

    public function test_super_admin_user_index_returns_only_admins(): void
    {
        $staff = User::factory()->create([
            'role' => 'staff',
            'site_id' => $this->site->id,
            'name' => 'Staff User',
        ]);

        $response = $this->actingAs($this->superAdmin)->getJson('/api/admin/users');

        $response->assertStatus(200);
        $users = $response->json('users');
        $roles = array_column($users, 'role');
        $this->assertNotContains('staff', $roles);
        $this->assertContains('admin', $roles);
    }
}
