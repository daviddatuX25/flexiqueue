<?php

namespace Tests\Feature\Api\Admin;

use App\Models\RbacTeam;
use App\Models\Site;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RbacTeamUserPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_admin_can_put_scoped_direct_permissions_for_same_site_user(): void
    {
        $site = Site::query()->create([
            'name' => 'Test Site',
            'slug' => 'test-'.Str::random(8),
            'api_key_hash' => 'hash-'.Str::random(32),
            'settings' => [],
            'edge_settings' => [],
            'is_default' => false,
        ]);
        $team = RbacTeam::forSite($site);
        $admin = User::factory()->admin()->create(['site_id' => $site->id]);
        $target = User::factory()->create(['site_id' => $site->id]);

        $response = $this->actingAs($admin)->putJson(
            "/api/admin/rbac-teams/{$team->id}/users/{$target->id}",
            ['direct_permissions' => [PermissionCatalog::KIOSK_ACCESS]]
        );

        $response->assertOk();
        $response->assertJsonPath('rbac_team_id', $team->id);
        $response->assertJsonPath('user_id', $target->id);

        $previous = getPermissionsTeamId();
        setPermissionsTeamId($team->id);
        try {
            $target->unsetRelation('roles')->unsetRelation('permissions');
            $this->assertTrue($target->can(PermissionCatalog::KIOSK_ACCESS));
        } finally {
            setPermissionsTeamId($previous);
            $target->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    public function test_global_team_returns_422(): void
    {
        $site = Site::query()->create([
            'name' => 'Test Site',
            'slug' => 'test-'.Str::random(8),
            'api_key_hash' => 'hash-'.Str::random(32),
            'settings' => [],
            'edge_settings' => [],
            'is_default' => false,
        ]);
        $admin = User::factory()->admin()->create(['site_id' => $site->id]);
        $target = User::factory()->create(['site_id' => $site->id]);

        $response = $this->actingAs($admin)->putJson(
            '/api/admin/rbac-teams/'.RbacTeam::GLOBAL_TEAM_ID.'/users/'.$target->id,
            ['direct_permissions' => []]
        );

        $response->assertStatus(422);
    }

    public function test_wrong_site_admin_gets_403(): void
    {
        $siteA = Site::query()->create([
            'name' => 'Site A',
            'slug' => 'a-'.Str::random(8),
            'api_key_hash' => 'hash-'.Str::random(32),
            'settings' => [],
            'edge_settings' => [],
            'is_default' => false,
        ]);
        $siteB = Site::query()->create([
            'name' => 'Site B',
            'slug' => 'b-'.Str::random(8),
            'api_key_hash' => 'hash-'.Str::random(32),
            'settings' => [],
            'edge_settings' => [],
            'is_default' => false,
        ]);
        $teamB = RbacTeam::forSite($siteB);
        $admin = User::factory()->admin()->create(['site_id' => $siteA->id]);
        $target = User::factory()->create(['site_id' => $siteB->id]);

        $response = $this->actingAs($admin)->putJson(
            "/api/admin/rbac-teams/{$teamB->id}/users/{$target->id}",
            ['direct_permissions' => [PermissionCatalog::KIOSK_ACCESS]]
        );

        $response->assertForbidden();
    }

    public function test_site_admin_can_get_scoped_direct_permissions(): void
    {
        $site = Site::query()->create([
            'name' => 'Test Site',
            'slug' => 'test-'.Str::random(8),
            'api_key_hash' => 'hash-'.Str::random(32),
            'settings' => [],
            'edge_settings' => [],
            'is_default' => false,
        ]);
        $team = RbacTeam::forSite($site);
        $admin = User::factory()->admin()->create(['site_id' => $site->id]);
        $target = User::factory()->create(['site_id' => $site->id]);

        $previous = getPermissionsTeamId();
        setPermissionsTeamId($team->id);
        try {
            $target->givePermissionTo(PermissionCatalog::DASHBOARD_VIEW);
        } finally {
            setPermissionsTeamId($previous);
            $target->unsetRelation('roles')->unsetRelation('permissions');
        }

        $response = $this->actingAs($admin)->getJson(
            "/api/admin/rbac-teams/{$team->id}/users/{$target->id}"
        );

        $response->assertOk();
        $response->assertJsonPath('rbac_team_id', $team->id);
        $response->assertJsonPath('user_id', $target->id);
        $response->assertJsonPath('direct_permissions', [PermissionCatalog::DASHBOARD_VIEW]);
    }
}
