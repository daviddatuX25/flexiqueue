<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Client;
use App\Models\Site;
use App\Models\Token;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Per site-scoping-migration-spec §5 & §7: Site isolation regression.
 * Two sites, two admins; no cross-site visibility for tokens, clients, or print settings.
 */
class SiteIsolationRegressionTest extends TestCase
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
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $this->siteB = Site::create([
            'name' => 'Site B',
            'slug' => 'site-b',
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $this->adminA = User::factory()->admin()->create(['site_id' => $this->siteA->id]);
        $this->adminB = User::factory()->admin()->create(['site_id' => $this->siteB->id]);
    }

    private function createTokenForSite(string $physicalId, int $siteId): Token
    {
        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).$physicalId);
        $token->physical_id = $physicalId;
        $token->site_id = $siteId;
        $token->status = 'available';
        $token->save();

        return $token;
    }

    private function createClientForSite(string $firstName, string $lastName, int $siteId): Client
    {
        return Client::create([
            'site_id' => $siteId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birth_date' => '1990-01-01',
        ]);
    }

    public function test_admin_a_sees_only_site_a_tokens_clients_print_settings(): void
    {
        $this->createTokenForSite('A1', $this->siteA->id);
        $this->createTokenForSite('A2', $this->siteA->id);
        $tokenB = $this->createTokenForSite('B1', $this->siteB->id);

        $this->createClientForSite('Alice', 'A', $this->siteA->id);
        $this->createClientForSite('Alan', 'A2', $this->siteA->id);
        $clientB = $this->createClientForSite('Bob', 'B', $this->siteB->id);

        // Token index: only site A
        $tokenResp = $this->actingAs($this->adminA)->getJson('/api/admin/tokens');
        $tokenResp->assertStatus(200);
        $physicalIds = collect($tokenResp->json('tokens'))->pluck('physical_id')->sort()->values()->all();
        $this->assertSame(['A1', 'A2'], $physicalIds);

        // Client index: only site A
        $clientResp = $this->actingAs($this->adminA)->get('/admin/clients');
        $clientResp->assertStatus(200);
        $clientResp->assertInertia(fn ($page) => $page
            ->has('clients')
            ->where('clients', fn ($clients) => count($clients) === 2
                && collect($clients)->map(fn ($c) => trim(($c['first_name'] ?? '').' '.($c['last_name'] ?? '')))->sort()->values()->all() === ['Alan A2', 'Alice A'])
        );

        // Print settings: site A row
        $printResp = $this->actingAs($this->adminA)->getJson('/api/admin/print-settings');
        $printResp->assertStatus(200);

        // Token update 404 for site B token
        $this->actingAs($this->adminA)
            ->putJson("/api/admin/tokens/{$tokenB->id}", ['status' => 'deactivated'])
            ->assertStatus(404);

        // Client show 404 for site B client
        $this->actingAs($this->adminA)
            ->get("/admin/clients/{$clientB->id}")
            ->assertStatus(404);
    }

    public function test_admin_b_sees_only_site_b_tokens_clients_print_settings(): void
    {
        $tokenA = $this->createTokenForSite('A1', $this->siteA->id);
        $this->createTokenForSite('B1', $this->siteB->id);
        $this->createTokenForSite('B2', $this->siteB->id);

        $clientA = $this->createClientForSite('Alice', 'A', $this->siteA->id);
        $this->createClientForSite('Bob', 'B', $this->siteB->id);
        $this->createClientForSite('Bella', 'B2', $this->siteB->id);

        // Token index: only site B
        $tokenResp = $this->actingAs($this->adminB)->getJson('/api/admin/tokens');
        $tokenResp->assertStatus(200);
        $physicalIds = collect($tokenResp->json('tokens'))->pluck('physical_id')->sort()->values()->all();
        $this->assertSame(['B1', 'B2'], $physicalIds);

        // Client index: only site B
        $clientResp = $this->actingAs($this->adminB)->get('/admin/clients');
        $clientResp->assertStatus(200);
        $clientResp->assertInertia(fn ($page) => $page
            ->has('clients')
            ->where('clients', fn ($clients) => count($clients) === 2
                && collect($clients)->map(fn ($c) => trim(($c['first_name'] ?? '').' '.($c['last_name'] ?? '')))->sort()->values()->all() === ['Bella B2', 'Bob B'])
        );

        // Print settings: site B row
        $printResp = $this->actingAs($this->adminB)->getJson('/api/admin/print-settings');
        $printResp->assertStatus(200);

        // Token update 404 for site A token
        $this->actingAs($this->adminB)
            ->putJson("/api/admin/tokens/{$tokenA->id}", ['status' => 'deactivated'])
            ->assertStatus(404);

        // Client show 404 for site A client
        $this->actingAs($this->adminB)
            ->get("/admin/clients/{$clientA->id}")
            ->assertStatus(404);
    }

    public function test_print_settings_are_per_site_admin_b_unchanged_when_admin_a_updates(): void
    {
        $this->actingAs($this->adminA);
        \Illuminate\Support\Facades\Session::start();
        $token = \Illuminate\Support\Facades\Session::token();

        $this->withHeader('X-CSRF-TOKEN', $token)->putJson('/api/admin/print-settings', [
            'cards_per_page' => 8,
            'paper' => 'letter',
            'orientation' => 'landscape',
            'show_hint' => false,
            'show_cut_lines' => false,
            'logo_url' => null,
            'footer_text' => 'Site A footer',
            'bg_image_url' => null,
        ])->assertStatus(200);

        $responseB = $this->actingAs($this->adminB)->getJson('/api/admin/print-settings');
        $responseB->assertStatus(200);
        $responseB->assertJsonPath('print_settings.cards_per_page', 6);
        $responseB->assertJsonPath('print_settings.paper', 'a4');
        $responseB->assertJsonPath('print_settings.footer_text', null);
    }
}
