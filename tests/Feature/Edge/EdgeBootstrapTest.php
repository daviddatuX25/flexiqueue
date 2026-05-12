<?php

namespace Tests\Feature\Edge;

use App\Models\EdgeDeviceState;
use App\Services\EdgeModeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class EdgeBootstrapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Enable edge mode
        File::ensureDirectoryExists(base_path('.edge'));
        file_put_contents(base_path('.edge/mode'), 'phone');
    }

    protected function tearDown(): void
    {
        if (file_exists(base_path('.edge/mode'))) {
            unlink(base_path('.edge/mode'));
        }
        if (is_dir(base_path('.edge'))) {
            rmdir(base_path('.edge'));
        }
        parent::tearDown();
    }

    public function test_boot_page_is_accessible_on_edge_mode(): void
    {
        $state = EdgeDeviceState::create([
            'id' => 1,
            'paired_at' => null,
            'active_program_id' => null,
            'is_revoked' => false,
            'approval_status' => 'approved',
        ]);

        $response = $this->get('/edge/boot');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Edge/Bootstrap')
            ->has('edgeRuntime')
        );
    }

    public function test_boot_page_not_redirected_by_edge_boot_guard(): void
    {
        $state = EdgeDeviceState::create([
            'id' => 1,
            'paired_at' => null,
            'active_program_id' => null,
            'is_revoked' => false,
            'approval_status' => 'approved',
        ]);

        $response = $this->get('/edge/boot');

        // Should NOT redirect to /edge/setup even though unpaired
        $response->assertOk();
    }

    public function test_boot_status_returns_redirect_path(): void
    {
        $state = EdgeDeviceState::create([
            'id' => 1,
            'paired_at' => null,
            'active_program_id' => null,
            'is_revoked' => false,
            'approval_status' => 'approved',
        ]);

        $response = $this->get('/edge/boot/status');

        $response->assertOk();
        $response->assertJsonStructure(['runtime', 'stack_healthy', 'redirect', 'state']);
        $response->assertJson(['redirect' => '/edge/setup']);
    }

    public function test_boot_status_redirects_to_awaiting_approval_when_pending(): void
    {
        $state = EdgeDeviceState::create([
            'id' => 1,
            'paired_at' => null,
            'active_program_id' => null,
            'is_revoked' => false,
            'approval_status' => 'pending',
        ]);

        $response = $this->get('/edge/boot/status');

        $response->assertJson(['redirect' => '/edge/awaiting-approval']);
    }

    public function test_boot_status_redirects_to_waiting_when_paired_no_program(): void
    {
        $state = EdgeDeviceState::create([
            'id' => 1,
            'paired_at' => now(),
            'active_program_id' => null,
            'is_revoked' => false,
            'approval_status' => 'approved',
        ]);

        $response = $this->get('/edge/boot/status');

        $response->assertJson(['redirect' => '/edge/waiting']);
    }

    public function test_boot_status_redirects_to_revoked_when_revoked(): void
    {
        $state = EdgeDeviceState::create([
            'id' => 1,
            'paired_at' => now(),
            'active_program_id' => 1,
            'is_revoked' => true,
            'approval_status' => 'approved',
        ]);

        $response = $this->get('/edge/boot/status');

        $response->assertJson(['redirect' => '/edge/revoked']);
    }

    public function test_boot_status_redirects_to_root_when_fully_paired(): void
    {
        $state = EdgeDeviceState::create([
            'id' => 1,
            'paired_at' => now(),
            'active_program_id' => 1,
            'is_revoked' => false,
            'approval_status' => 'approved',
        ]);

        $response = $this->get('/edge/boot/status');

        $response->assertJson(['redirect' => '/']);
    }

    public function test_boot_page_accessible_without_auth(): void
    {
        $state = EdgeDeviceState::create([
            'id' => 1,
            'paired_at' => null,
            'active_program_id' => null,
            'is_revoked' => false,
            'approval_status' => 'approved',
        ]);

        // Should not redirect to login
        $response = $this->get('/edge/boot');
        $response->assertOk();
    }
}