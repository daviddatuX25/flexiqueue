<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Per site-scoping-migration-spec §3 & §7: Client API and pages scoped by site.
 * Site admin A sees only site A clients; create sets site_id; show/destroy 404 for other site; search scoped.
 */
class ClientSiteIsolationTest extends TestCase
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

    private function createClientForSite(string $name, int $siteId, int $birthYear = 1990): Client
    {
        $parts = preg_split('/\s+/', trim($name), 2, PREG_SPLIT_NO_EMPTY);
        $firstName = $parts[0] ?? 'Unknown';
        $lastName = $parts[1] ?? $firstName;

        return Client::create([
            'site_id' => $siteId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birth_date' => sprintf('%d-01-01', $birthYear),
        ]);
    }

    public function test_site_admin_a_lists_only_site_a_clients(): void
    {
        $this->createClientForSite('Alice A', $this->siteA->id);
        $this->createClientForSite('Alan A2', $this->siteA->id);
        $this->createClientForSite('Bob B', $this->siteB->id);

        $response = $this->actingAs($this->adminA)->get('/admin/clients');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Clients/Index')
            ->has('clients')
            ->where('clients', fn ($clients) => count($clients) === 2
                && collect($clients)->map(fn ($c) => trim(implode(' ', array_filter([
                    $c['first_name'] ?? '',
                    $c['middle_name'] ?? '',
                    $c['last_name'] ?? '',
                ], fn ($x) => $x !== ''))))->sort()->values()->all() === ['Alan A2', 'Alice A'])
        );
    }

    public function test_site_admin_b_lists_only_site_b_clients(): void
    {
        $this->createClientForSite('Alice A', $this->siteA->id);
        $this->createClientForSite('Bob B', $this->siteB->id);
        $this->createClientForSite('Bella B2', $this->siteB->id);

        $response = $this->actingAs($this->adminB)->get('/admin/clients');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Clients/Index')
            ->has('clients')
            ->where('clients', fn ($clients) => count($clients) === 2
                && collect($clients)->map(fn ($c) => trim(implode(' ', array_filter([
                    $c['first_name'] ?? '',
                    $c['middle_name'] ?? '',
                    $c['last_name'] ?? '',
                ], fn ($x) => $x !== ''))))->sort()->values()->all() === ['Bella B2', 'Bob B'])
        );
    }

    public function test_client_create_sets_site_id(): void
    {
        $response = $this->actingAs($this->adminA)->postJson('/api/clients', [
            'first_name' => 'New',
            'last_name' => 'Client',
            'birth_date' => '1985-01-01',
        ]);

        $response->assertStatus(201);
        $clientId = $response->json('client.id');
        $this->assertDatabaseHas('clients', [
            'id' => $clientId,
            'site_id' => $this->siteA->id,
        ]);
    }

    public function test_site_admin_with_null_site_id_gets_403_on_index(): void
    {
        $adminNoSite = User::factory()->admin()->create(['site_id' => null]);
        $this->createClientForSite('Alice', $this->siteA->id);

        $response = $this->actingAs($adminNoSite)->get('/admin/clients');

        $response->assertStatus(403);
    }

    public function test_client_show_404_for_other_site(): void
    {
        $clientB = $this->createClientForSite('Bob B', $this->siteB->id);

        $response = $this->actingAs($this->adminA)->get("/admin/clients/{$clientB->id}");

        $response->assertStatus(404);
    }

    public function test_client_destroy_404_for_other_site(): void
    {
        $clientB = $this->createClientForSite('Bob B', $this->siteB->id);

        $response = $this->actingAs($this->adminA)->deleteJson("/api/admin/clients/{$clientB->id}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('clients', ['id' => $clientB->id]);
    }

    public function test_search_clients_scoped_by_site(): void
    {
        $this->createClientForSite('Alice Alpha', $this->siteA->id, 1980);
        $this->createClientForSite('Bob Alpha', $this->siteB->id, 1980);

        $response = $this->actingAs($this->adminA)->getJson('/api/clients/search?name=Alpha&birth_date=1980-01-01');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Alice', $data[0]['first_name']);
        $this->assertSame('Alpha', $data[0]['last_name']);
    }

    /**
     * Per site-scoping-migration-spec §3: IdentityRegistrationController accept must reject
     * client_id from another site when linking existing client.
     */
    public function test_identity_registration_accept_404_when_client_from_other_site(): void
    {
        $programA = \App\Models\Program::create([
            'site_id' => $this->siteA->id,
            'name' => 'Program A',
            'description' => null,
            'is_active' => true,
            'created_by' => $this->adminA->id,
            'settings' => [],
        ]);
        $stationA = \App\Models\Station::create([
            'program_id' => $programA->id,
            'name' => 'Station A',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $clientB = $this->createClientForSite('Bob Other Site', $this->siteB->id);

        $reg = \App\Models\IdentityRegistration::create([
            'program_id' => $programA->id,
            'session_id' => null,
            'first_name' => 'Alice',
            'last_name' => 'Pending',
            'birth_date' => '1990-01-01',
            'client_category' => 'Regular',
            'client_id' => null,
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        $this->adminA->update(['assigned_station_id' => $stationA->id]);

        $response = $this->actingAs($this->adminA)->postJson("/api/identity-registrations/{$reg->id}/accept", [
            'first_name' => 'Alice',
            'last_name' => 'Pending',
            'birth_date' => '1990-01-01',
            'client_category' => 'Regular',
            'client_id' => $clientB->id,
        ]);

        $response->assertStatus(404);
        $response->assertJson(['message' => 'Client does not belong to this program\'s site.']);
        $reg->refresh();
        $this->assertSame('pending', $reg->status);
    }
}
