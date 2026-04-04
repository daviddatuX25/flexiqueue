<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Per assign-site-to-user follow-up: super_admin can assign/change user site; site admin cannot.
 */
class UserSiteAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_list_all_users_with_site(): void
    {
        $siteA = Site::create([
            'name' => 'Site A',
            'slug' => 'site-a',
            'api_key_hash' => bcrypt(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $siteB = Site::create([
            'name' => 'Site B',
            'slug' => 'site-b',
            'api_key_hash' => bcrypt(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $superAdmin = User::factory()->superAdmin()->create(['site_id' => null]);
        $userA = User::factory()->admin()->create(['site_id' => $siteA->id, 'name' => 'User A']);
        $userB = User::factory()->admin()->create(['site_id' => $siteB->id, 'name' => 'User B']);

        $response = $this->actingAs($superAdmin)->getJson('/api/admin/users');

        $response->assertStatus(200);
        $users = $response->json('users');
        $this->assertCount(2, $users); // Per SUPER-ADMIN-VS-ADMIN-SPEC: super_admin sees only admins (userA, userB)
        $names = array_column($users, 'name');
        $this->assertContains('User A', $names);
        $this->assertContains('User B', $names);
        $firstWithSite = collect($users)->first(fn ($u) => isset($u['site']));
        $this->assertNotNull($firstWithSite);
        $this->assertArrayHasKey('site', $firstWithSite);
        $this->assertSame($siteA->id, $firstWithSite['site']['id']);
    }

    public function test_super_admin_can_update_user_site_id(): void
    {
        $siteA = Site::create([
            'name' => 'Site A',
            'slug' => 'site-a',
            'api_key_hash' => bcrypt(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $siteB = Site::create([
            'name' => 'Site B',
            'slug' => 'site-b',
            'api_key_hash' => bcrypt(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $superAdmin = User::factory()->superAdmin()->create(['site_id' => null]);
        $user = User::factory()->admin()->create(['site_id' => $siteA->id, 'name' => 'Movable User']);

        $response = $this->actingAs($superAdmin)->putJson("/api/admin/users/{$user->id}", [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'role' => $user->primaryGlobalRoleName() ?? 'staff',
            'is_active' => true,
            'site_id' => $siteB->id,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'site_id' => $siteB->id]);
        $response->assertJsonPath('user.site.id', $siteB->id);
    }

    public function test_site_admin_cannot_change_user_site_id(): void
    {
        $siteA = Site::create([
            'name' => 'Site A',
            'slug' => 'site-a',
            'api_key_hash' => bcrypt(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $siteB = Site::create([
            'name' => 'Site B',
            'slug' => 'site-b',
            'api_key_hash' => bcrypt(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $admin = User::factory()->admin()->create(['site_id' => $siteA->id]);
        $userInA = User::factory()->create(['site_id' => $siteA->id, 'name' => 'User in A']);

        $response = $this->actingAs($admin)->putJson("/api/admin/users/{$userInA->id}", [
            'name' => $userInA->name,
            'username' => $userInA->username,
            'email' => $userInA->email,
            'role' => $userInA->primaryGlobalRoleName() ?? 'staff',
            'is_active' => true,
            'site_id' => $siteB->id,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', ['id' => $userInA->id, 'site_id' => $siteA->id]);
    }

    public function test_super_admin_can_create_user_with_site_id(): void
    {
        $site = Site::create([
            'name' => 'Target Site',
            'slug' => 'target',
            'api_key_hash' => bcrypt(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $superAdmin = User::factory()->superAdmin()->create(['site_id' => null]);

        $response = $this->actingAs($superAdmin)->postJson('/api/admin/users', [
            'name' => 'New Admin',
            'username' => 'newadmin.site',
            'email' => 'newadmin@example.com',
            'recovery_gmail' => 'newadmin.recovery@gmail.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'admin',
            'site_id' => $site->id,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', ['email' => 'newadmin@example.com', 'site_id' => $site->id]);
        $created = User::where('email', 'newadmin@example.com')->first();
        $this->assertNotNull($created);
        $this->assertTrue($created->hasSpatieRole('admin'));
        $response->assertJsonPath('user.site.id', $site->id);
    }

    public function test_super_admin_cannot_create_staff_account(): void
    {
        $site = Site::create([
            'name' => 'Site',
            'slug' => 'site',
            'api_key_hash' => bcrypt(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $superAdmin = User::factory()->superAdmin()->create(['site_id' => null]);

        $response = $this->actingAs($superAdmin)->postJson('/api/admin/users', [
            'name' => 'New Staff',
            'username' => 'newstaff.blocked',
            'email' => 'staff@example.com',
            'recovery_gmail' => 'staff.recovery@gmail.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'site_id' => $site->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['role']);
        $this->assertDatabaseMissing('users', ['email' => 'staff@example.com']);
    }

    public function test_site_admin_cannot_create_admin_account(): void
    {
        $site = Site::create([
            'name' => 'Site',
            'slug' => 'site',
            'api_key_hash' => bcrypt(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $admin = User::factory()->admin()->create(['site_id' => $site->id]);

        $response = $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'Another Admin',
            'username' => 'another.admin',
            'email' => 'admin2@example.com',
            'recovery_gmail' => 'admin2.recovery@gmail.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'admin',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'admin2@example.com',
            'site_id' => $site->id,
        ]);
        $created = User::where('email', 'admin2@example.com')->first();
        $this->assertNotNull($created);
        $this->assertTrue($created->hasSpatieRole('admin'));
    }

    public function test_site_admin_cannot_update_user_role_to_admin(): void
    {
        $site = Site::create([
            'name' => 'Site',
            'slug' => 'site',
            'api_key_hash' => bcrypt(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $admin = User::factory()->admin()->create(['site_id' => $site->id]);
        $staff = User::factory()->create(['site_id' => $site->id]);

        $response = $this->actingAs($admin)->putJson("/api/admin/users/{$staff->id}", [
            'name' => $staff->name,
            'username' => $staff->username,
            'email' => $staff->email,
            'role' => 'admin',
            'is_active' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['role']);
        $this->assertDatabaseHas('users', ['id' => $staff->id]);
        $this->assertTrue($staff->fresh()->hasSpatieRole('staff'));
    }

    public function test_cannot_create_super_admin_via_api(): void
    {
        $site = Site::create([
            'name' => 'Site',
            'slug' => 'site',
            'api_key_hash' => bcrypt(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $superAdmin = User::factory()->superAdmin()->create(['site_id' => null]);

        $response = $this->actingAs($superAdmin)->postJson('/api/admin/users', [
            'name' => 'Fake Super',
            'username' => 'fakesuper.user',
            'email' => 'super@example.com',
            'recovery_gmail' => 'super.recovery@gmail.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'super_admin',
            'site_id' => $site->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['role']);
        $this->assertDatabaseMissing('users', ['email' => 'super@example.com']);
    }

    public function test_super_admin_can_access_user_in_another_site(): void
    {
        $siteA = Site::create([
            'name' => 'Site A',
            'slug' => 'site-a',
            'api_key_hash' => bcrypt(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $siteB = Site::create([
            'name' => 'Site B',
            'slug' => 'site-b',
            'api_key_hash' => bcrypt(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $superAdmin = User::factory()->superAdmin()->create(['site_id' => null]);
        $userInB = User::factory()->admin()->create(['site_id' => $siteB->id]);

        $response = $this->actingAs($superAdmin)->getJson("/api/admin/users?site_id={$siteB->id}");

        $response->assertStatus(200);
        $users = $response->json('users');
        $this->assertGreaterThanOrEqual(1, count($users));
        $ids = array_column($users, 'id');
        $this->assertContains($userInB->id, $ids);
    }
}
