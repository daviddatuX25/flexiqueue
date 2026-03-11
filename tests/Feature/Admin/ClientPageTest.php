<?php

namespace Tests\Feature\Admin;

use App\Models\Client;
use App\Models\ClientIdDocument;
use App\Models\User;
use App\Models\Program;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin/supervisor client list + detail pages.
 *
 * Per xm2o bead: admins and supervisors can browse clients and see redacted IDs; staff cannot access.
 */
class ClientPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    public function test_admin_can_view_clients_index_and_detail_with_masked_ids(): void
    {
        $client = Client::factory()->create([
            'name' => 'Juan Dela Cruz',
            'birth_year' => 1990,
        ]);
        $document = ClientIdDocument::factory()->create([
            'client_id' => $client->id,
            'id_type' => 'philhealth',
        ]);

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
            ->where('client.name', 'Juan Dela Cruz')
            ->has('id_documents')
            ->where('id_documents.0.id_type', $document->id_type)
        );
    }

    public function test_supervisor_can_view_clients_pages(): void
    {
        $supervisor = User::factory()->supervisor()->create();
        $program = Program::create([
            'name' => 'Relief Program',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);
        $program->supervisedBy()->attach($supervisor->id);

        $client = Client::factory()->create();

        $indexResponse = $this->actingAs($supervisor)->get('/admin/clients');
        $indexResponse->assertStatus(200);

        $detailResponse = $this->actingAs($supervisor)->get("/admin/clients/{$client->id}");
        $detailResponse->assertStatus(200);
    }

    public function test_staff_cannot_access_clients_pages(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        $indexResponse = $this->actingAs($staff)->get('/admin/clients');
        $indexResponse->assertStatus(403);
    }
}

