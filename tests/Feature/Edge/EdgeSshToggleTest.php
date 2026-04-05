<?php

namespace Tests\Feature\Edge;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EdgeSshToggleTest extends TestCase
{
    use RefreshDatabase;

    private function actAsEdge(): void
    {
        config(['app.mode' => 'edge']);
    }

    public function test_ssh_enable_returns_404_on_central(): void
    {
        config(['app.mode' => 'central']);
        $user = User::factory()->admin()->create();

        $this->actingAs($user)
            ->postJson('/api/edge/ssh/enable')
            ->assertNotFound();
    }

    public function test_ssh_enable_requires_authentication(): void
    {
        $this->actAsEdge();

        $this->postJson('/api/edge/ssh/enable')
            ->assertUnauthorized();
    }

    public function test_ssh_enable_requires_admin_permission(): void
    {
        $this->actAsEdge();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/edge/ssh/enable')
            ->assertForbidden();
    }

    public function test_ssh_enable_returns_503_when_script_missing(): void
    {
        $this->actAsEdge();
        config(['flexiqueue.edge_ssh_enable_script' => '/nonexistent/flexiqueue-enable-ssh']);
        $user = User::factory()->admin()->create();

        $this->actingAs($user)
            ->postJson('/api/edge/ssh/enable')
            ->assertStatus(503)
            ->assertJsonPath('error', 'SSH toggle not available on this device.');
    }
}