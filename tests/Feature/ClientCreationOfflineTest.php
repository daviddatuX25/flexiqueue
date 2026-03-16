<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Program;
use App\Models\Site;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Per docs/final-edge-mode-rush-plann.md [DF-22]: Client creation and mobile update blocked when edge (offline).
 */
class ClientCreationOfflineTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $admin;

    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'api_key_hash' => \Illuminate\Support\Facades\Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $this->admin = User::factory()->admin()->create(['site_id' => $this->site->id]);
        $this->program = Program::create([
            'site_id' => $this->site->id,
            'name' => 'Test Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        $station = Station::create([
            'program_id' => $this->program->id,
            'name' => 'S1',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $this->admin->update(['assigned_station_id' => $station->id]);
    }

    public function test_post_clients_returns_403_with_message_when_app_mode_edge(): void
    {
        config(['app.mode' => 'edge']);

        $response = $this->actingAs($this->admin)->postJson('/api/clients', [
            'first_name' => 'Edge',
            'last_name' => 'Blocked',
            'birth_date' => '1990-01-01',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'Client creation is not available in offline mode. Clients must be synced from the central server.');
    }

    public function test_post_clients_returns_201_when_app_mode_central(): void
    {
        config(['app.mode' => 'central']);

        $response = $this->actingAs($this->admin)->postJson('/api/clients', [
            'first_name' => 'Central',
            'last_name' => 'Created',
            'birth_date' => '1985-06-15',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('clients', ['first_name' => 'Central', 'last_name' => 'Created']);
    }

    public function test_put_clients_mobile_returns_403_when_app_mode_edge(): void
    {
        config(['app.mode' => 'edge']);
        $client = Client::create([
            'site_id' => $this->site->id,
            'first_name' => 'Mobile',
            'last_name' => 'User',
            'birth_date' => '1980-01-01',
        ]);

        $response = $this->actingAs($this->admin)->putJson("/api/clients/{$client->id}/mobile", [
            'mobile' => '09171234567',
            'reason' => 'Test reason',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'Mobile number updates are not available in offline mode.');
    }

    public function test_get_clients_search_returns_200_when_app_mode_edge(): void
    {
        config(['app.mode' => 'edge']);

        $response = $this->actingAs($this->admin)->getJson('/api/clients/search?name=Test&birth_date=1990-01-01');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data', 'meta']);
    }
}
