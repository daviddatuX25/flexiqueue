<?php

namespace Tests\Feature\Admin;

use App\Models\Site;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class UsersPageRbacTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_users_page_includes_assignable_permissions_and_user_permission_fields(): void
    {
        $site = Site::create([
            'name' => 'S',
            'slug' => 's-'.Str::random(6),
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $admin = User::factory()->admin()->create(['site_id' => $site->id]);

        $response = $this->actingAs($admin)->get(route('admin.users'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('assignable_permissions')
            ->where('assignable_permissions', PermissionCatalog::assignableDirect())
            ->has('users.0.direct_permissions')
            ->has('users.0.effective_permissions')
        );
    }
}
