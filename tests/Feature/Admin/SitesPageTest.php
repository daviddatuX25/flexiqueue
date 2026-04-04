<?php

namespace Tests\Feature\Admin;

use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Per central-edge B.5: Admin UI for site management. RBAC: site admin sees only own site; only super_admin may create sites.
 */
class SitesPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $superAdmin;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = $this->createSite(['name' => 'Default Site', 'slug' => 'default']);
        $this->admin = User::factory()->admin()->create(['site_id' => $this->site->id]);
        $this->superAdmin = User::factory()->superAdmin()->create(['site_id' => null]);
    }

    private function createSite(array $attrs = []): Site
    {
        return Site::create(array_merge([
            'name' => 'Test Site',
            'slug' => 'test-site-'.uniqid(),
            'api_key_hash' => Hash::make('dummy'),
            'settings' => [],
            'edge_settings' => [],
        ], $attrs));
    }

    /** Per plan: site-scoped admin is redirected from /admin/sites to dashboard (no Sites nav). */
    public function test_site_admin_can_access_sites_index_sees_only_own_site(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.sites'));

        $response->assertRedirect(route('admin.dashboard'));
    }

    public function test_site_admin_cannot_access_sites_create_returns_403(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.sites.create'));

        $response->assertStatus(403);
    }

    public function test_super_admin_can_access_sites_create_page(): void
    {
        $response = $this->actingAs($this->superAdmin)->get(route('admin.sites.create'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Admin/Sites/Create'));
    }

    public function test_site_admin_can_access_own_site_show_page_sees_masked_key_only(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.sites.show', $this->site));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Sites/Show')
            ->has('site')
            ->where('site.id', $this->site->id)
            ->where('site.name', 'Default Site')
            ->where('api_key_masked', 'sk_live_...****')
            ->missing('api_key'));
    }

    public function test_site_admin_show_other_site_returns_404(): void
    {
        $other = $this->createSite(['name' => 'Other', 'slug' => 'other']);

        $response = $this->actingAs($this->admin)->get(route('admin.sites.show', $other));

        $response->assertStatus(404);
    }

    public function test_super_admin_index_lists_all_sites(): void
    {
        $this->createSite(['name' => 'Site A', 'slug' => 'site-a']);
        $this->createSite(['name' => 'Site B', 'slug' => 'site-b']);

        $response = $this->actingAs($this->superAdmin)->get(route('admin.sites'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->has('sites', 3)
            ->where('auth_is_super_admin', true));
    }

    public function test_staff_cannot_access_sites_index_returns_403(): void
    {
        $staff = User::factory()->create();

        $response = $this->actingAs($staff)->get(route('admin.sites'));

        $response->assertStatus(403);
    }

    public function test_staff_cannot_access_site_show_returns_403(): void
    {
        $staff = User::factory()->create();
        $site = $this->createSite();

        $response = $this->actingAs($staff)->get(route('admin.sites.show', $site));

        $response->assertStatus(403);
    }
}
