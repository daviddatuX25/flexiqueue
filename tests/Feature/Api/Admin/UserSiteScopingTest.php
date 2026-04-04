<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Per central-edge B.4: Users are scoped by site_id; cross-site isolation.
 */
class UserSiteScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_of_site_a_sees_only_site_a_users_in_index(): void
    {
        $siteA = Site::create([
            'name' => 'Site A',
            'slug' => 'site-a',
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $siteB = Site::create([
            'name' => 'Site B',
            'slug' => 'site-b',
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);

        $adminA = User::factory()->admin()->create(['site_id' => $siteA->id]);
        $adminB = User::factory()->admin()->create(['site_id' => $siteB->id]);
        $userA1 = User::factory()->create(['site_id' => $siteA->id]);
        $userA2 = User::factory()->create(['site_id' => $siteA->id]);
        $userB1 = User::factory()->create(['site_id' => $siteB->id]);

        $responseA = $this->actingAs($adminA)->getJson('/api/admin/users');
        $responseA->assertStatus(200);
        $idsA = collect($responseA->json('users'))->pluck('id')->all();
        $this->assertContains($adminA->id, $idsA);
        $this->assertContains($userA1->id, $idsA);
        $this->assertContains($userA2->id, $idsA);
        $this->assertNotContains($userB1->id, $idsA);
        $this->assertNotContains($adminB->id, $idsA);

        $responseB = $this->actingAs($adminB)->getJson('/api/admin/users');
        $responseB->assertStatus(200);
        $idsB = collect($responseB->json('users'))->pluck('id')->all();
        $this->assertContains($adminB->id, $idsB);
        $this->assertContains($userB1->id, $idsB);
        $this->assertNotContains($userA1->id, $idsB);
        $this->assertNotContains($adminA->id, $idsB);
    }

    public function test_admin_of_site_a_accessing_site_b_user_by_id_gets_404(): void
    {
        $siteA = Site::create([
            'name' => 'Site A',
            'slug' => 'site-a',
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $siteB = Site::create([
            'name' => 'Site B',
            'slug' => 'site-b',
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $adminA = User::factory()->admin()->create(['site_id' => $siteA->id]);
        $userB = User::factory()->create(['site_id' => $siteB->id]);

        // No GET /api/admin/users/{id} route; only PUT/DELETE are used for single-user access
        $this->actingAs($adminA)->putJson("/api/admin/users/{$userB->id}", [
            'name' => $userB->name,
            'username' => $userB->username,
            'email' => $userB->email,
            'is_active' => true,
        ])->assertStatus(404);
        $this->actingAs($adminA)->deleteJson("/api/admin/users/{$userB->id}")->assertStatus(404);
    }

    public function test_admin_with_null_site_id_sees_empty_user_list(): void
    {
        $site = Site::create([
            'name' => 'Default',
            'slug' => 'default',
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $adminNoSite = User::factory()->admin()->create(['site_id' => null]);
        User::factory()->create(['site_id' => $site->id]);

        $response = $this->actingAs($adminNoSite)->getJson('/api/admin/users');
        $response->assertStatus(200);
        $this->assertSame([], $response->json('users'));
    }

    public function test_admin_with_null_site_id_cannot_create_user_gets_403(): void
    {
        $adminNoSite = User::factory()->admin()->create(['site_id' => null]);

        $response = $this->actingAs($adminNoSite)->postJson('/api/admin/users', [
            'name' => 'New User',
            'username' => 'new.user',
            'email' => 'new@example.com',
            'recovery_gmail' => 'new.recovery@gmail.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'staff',
        ]);
        $response->assertStatus(403);
        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
    }

    public function test_store_sets_site_id_from_authenticated_user(): void
    {
        $site = Site::create([
            'name' => 'Default',
            'slug' => 'default',
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $admin = User::factory()->admin()->create(['site_id' => $site->id]);

        $response = $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'New User',
            'username' => 'newuser.staff',
            'email' => 'newuser@example.com',
            'recovery_gmail' => 'newuser.recovery@gmail.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'staff',
        ]);
        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'newuser@example.com',
            'site_id' => $site->id,
        ]);
    }
}
