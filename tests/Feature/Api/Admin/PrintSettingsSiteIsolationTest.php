<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Per site-scoping-migration-spec §4 & §7: Print settings per site.
 * Two sites; admin A updates print settings; admin B sees own row unchanged.
 */
class PrintSettingsSiteIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Site $siteA;

    private Site $siteB;

    private User $adminA;

    private User $adminB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siteA = Site::create([
            'name' => 'Site A',
            'slug' => 'site-a',
            'api_key_hash' => \Illuminate\Support\Facades\Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $this->siteB = Site::create([
            'name' => 'Site B',
            'slug' => 'site-b',
            'api_key_hash' => \Illuminate\Support\Facades\Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $this->adminA = User::factory()->admin()->create(['site_id' => $this->siteA->id]);
        $this->adminB = User::factory()->admin()->create(['site_id' => $this->siteB->id]);
    }

    public function test_admin_a_updates_print_settings_admin_b_sees_own_unchanged(): void
    {
        // Admin A: get defaults then update to distinct values
        $responseA = $this->actingAs($this->adminA)->getJson('/api/admin/print-settings');
        $responseA->assertStatus(200);
        $responseA->assertJsonPath('print_settings.cards_per_page', 6);

        $this->actingAs($this->adminA);
        \Illuminate\Support\Facades\Session::start();
        $token = \Illuminate\Support\Facades\Session::token();

        $this->withHeader('X-CSRF-TOKEN', $token)->putJson('/api/admin/print-settings', [
            'cards_per_page' => 8,
            'paper' => 'letter',
            'orientation' => 'landscape',
            'show_hint' => false,
            'show_cut_lines' => false,
            'logo_url' => 'https://site-a.com/logo.png',
            'footer_text' => 'Site A footer',
            'bg_image_url' => 'https://site-a.com/bg.png',
        ])->assertStatus(200);

        // Admin B: should see default (own site's row), not A's values
        $responseB = $this->actingAs($this->adminB)->getJson('/api/admin/print-settings');
        $responseB->assertStatus(200);
        $responseB->assertJsonPath('print_settings.cards_per_page', 6);
        $responseB->assertJsonPath('print_settings.paper', 'a4');
        $responseB->assertJsonPath('print_settings.orientation', 'portrait');
        $responseB->assertJsonPath('print_settings.footer_text', null);
        $responseB->assertJsonPath('print_settings.logo_url', null);

        // Admin A: still sees updated values
        $responseA2 = $this->actingAs($this->adminA)->getJson('/api/admin/print-settings');
        $responseA2->assertStatus(200);
        $responseA2->assertJsonPath('print_settings.cards_per_page', 8);
        $responseA2->assertJsonPath('print_settings.paper', 'letter');
        $responseA2->assertJsonPath('print_settings.footer_text', 'Site A footer');
    }

    public function test_site_admin_with_null_site_id_gets_403_on_print_settings(): void
    {
        $adminNoSite = User::factory()->admin()->create(['site_id' => null]);

        $this->actingAs($adminNoSite)->getJson('/api/admin/print-settings')->assertStatus(403);

        $this->actingAs($adminNoSite);
        \Illuminate\Support\Facades\Session::start();
        $token = \Illuminate\Support\Facades\Session::token();
        $this->withHeader('X-CSRF-TOKEN', $token)->putJson('/api/admin/print-settings', [
            'cards_per_page' => 8,
        ])->assertStatus(403);
    }
}
