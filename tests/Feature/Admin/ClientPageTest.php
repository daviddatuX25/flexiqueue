<?php

namespace Tests\Feature\Admin;

use App\Models\Client;
use App\Models\Program;
use App\Models\Site;
use App\Models\User;
use App\Services\ClientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Admin/supervisor client list + detail pages.
 *
 * Per xm2o bead: admins and supervisors can browse clients and see redacted IDs; staff cannot access.
 * Per site-scoping-migration-spec §3: scoped by site_id.
 */
class ClientPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::create([
            'name' => 'Default',
            'slug' => 'default',
            'api_key_hash' => Hash::make('key'),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $this->admin = User::factory()->admin()->create(['site_id' => $this->site->id]);
    }

    public function test_admin_can_view_clients_index_and_detail_with_masked_ids(): void
    {
        $client = app(ClientService::class)->createClient(
            'Juan',
            'Cruz',
            '1990-01-01',
            $this->site->id,
            '09171234567',
            'Dela'
        );

        $indexResponse = $this->actingAs($this->admin)->get('/admin/clients');
        $indexResponse->assertStatus(200);
        $indexResponse->assertInertia(fn ($page) => $page
            ->component('Admin/Clients/Index')
            ->has('clients')
        );

        $detailResponse = $this->actingAs($this->admin)->get("/admin/clients/{$client->id}");
        $detailResponse->assertStatus(200);
        $detailResponse->assertInertia(fn ($page) => $page
            ->component('Admin/Clients/Show')
            ->where('client.id', $client->id)
            ->where('client.first_name', 'Juan')
            ->where('client.last_name', 'Cruz')
            ->has('client.mobile_masked')
        );
    }

    public function test_supervisor_can_view_clients_pages(): void
    {
        $supervisor = User::factory()->supervisor()->create(['site_id' => $this->site->id]);
        $program = Program::create([
            'site_id' => $this->site->id,
            'name' => 'Relief Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        $this->grantProgramTeamSuperviseForTests($supervisor, $program);

        $client = Client::factory()->create(['site_id' => $this->site->id]);

        $indexResponse = $this->actingAs($supervisor)->get('/admin/clients');
        $indexResponse->assertStatus(200);

        $detailResponse = $this->actingAs($supervisor)->get("/admin/clients/{$client->id}");
        $detailResponse->assertStatus(200);
    }

    public function test_staff_cannot_access_clients_pages(): void
    {
        $staff = User::factory()->create();

        $indexResponse = $this->actingAs($staff)->get('/admin/clients');
        $indexResponse->assertStatus(403);
    }
}
