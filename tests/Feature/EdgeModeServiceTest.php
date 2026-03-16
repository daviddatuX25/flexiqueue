<?php

namespace Tests\Feature;

use App\Services\EdgeModeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per docs/final-edge-mode-rush-plann.md [DF-19]: EdgeModeService behavior for central vs edge mode.
 */
class EdgeModeServiceTest extends TestCase
{
    use RefreshDatabase;

    private EdgeModeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EdgeModeService::class);
    }

    public function test_when_mode_is_central_is_edge_returns_false(): void
    {
        config(['app.mode' => 'central']);
        $this->assertFalse($this->service->isEdge());
    }

    public function test_when_mode_is_central_is_central_returns_true(): void
    {
        config(['app.mode' => 'central']);
        $this->assertTrue($this->service->isCentral());
    }

    public function test_when_mode_is_central_is_online_returns_false(): void
    {
        config(['app.mode' => 'central']);
        $this->assertFalse($this->service->isOnline());
    }

    public function test_when_mode_is_central_is_offline_returns_false(): void
    {
        config(['app.mode' => 'central']);
        $this->assertFalse($this->service->isOffline());
    }

    public function test_when_mode_is_central_is_admin_read_only_returns_false(): void
    {
        config(['app.mode' => 'central']);
        $this->assertFalse($this->service->isAdminReadOnly());
    }

    public function test_when_mode_is_central_can_create_clients_returns_true(): void
    {
        config(['app.mode' => 'central']);
        $this->assertTrue($this->service->canCreateClients());
    }

    public function test_when_mode_is_central_can_register_identity_returns_true(): void
    {
        config(['app.mode' => 'central']);
        $this->assertTrue($this->service->canRegisterIdentity());
    }

    public function test_when_mode_is_edge_is_edge_returns_true(): void
    {
        config(['app.mode' => 'edge']);
        $this->assertTrue($this->service->isEdge());
    }

    public function test_when_mode_is_edge_is_central_returns_false(): void
    {
        config(['app.mode' => 'edge']);
        $this->assertFalse($this->service->isCentral());
    }

    public function test_when_mode_is_edge_is_online_returns_false(): void
    {
        config(['app.mode' => 'edge']);
        $this->assertFalse($this->service->isOnline());
    }

    public function test_when_mode_is_edge_is_offline_returns_true(): void
    {
        config(['app.mode' => 'edge']);
        $this->assertTrue($this->service->isOffline());
    }

    public function test_when_mode_is_edge_is_admin_read_only_returns_true(): void
    {
        config(['app.mode' => 'edge']);
        $this->assertTrue($this->service->isAdminReadOnly());
    }

    public function test_when_mode_is_edge_can_create_clients_returns_false(): void
    {
        config(['app.mode' => 'edge']);
        $this->assertFalse($this->service->canCreateClients());
    }

    public function test_when_mode_is_edge_can_register_identity_returns_false(): void
    {
        config(['app.mode' => 'edge']);
        $this->assertFalse($this->service->canRegisterIdentity());
    }

    public function test_get_effective_binding_mode_required_returns_optional_when_edge(): void
    {
        config(['app.mode' => 'edge']);
        $this->assertSame('optional', $this->service->getEffectiveBindingMode('required'));
    }

    public function test_get_effective_binding_mode_disabled_returns_disabled_when_edge(): void
    {
        config(['app.mode' => 'edge']);
        $this->assertSame('disabled', $this->service->getEffectiveBindingMode('disabled'));
    }

    public function test_get_effective_binding_mode_required_returns_required_when_central(): void
    {
        config(['app.mode' => 'central']);
        $this->assertSame('required', $this->service->getEffectiveBindingMode('required'));
    }

    public function test_get_effective_binding_mode_disabled_returns_disabled_when_central(): void
    {
        config(['app.mode' => 'central']);
        $this->assertSame('disabled', $this->service->getEffectiveBindingMode('disabled'));
    }

    /** When edge, sync_back is always false (design: edge does not sync back). */
    public function test_sync_back_returns_false_when_edge(): void
    {
        config(['app.mode' => 'edge', 'app.sync_back' => true]);
        $this->assertFalse($this->service->syncBack());
    }

    /** When central and SYNC_BACK=true, sync_back is true. */
    public function test_sync_back_returns_true_when_central_and_config_true(): void
    {
        config(['app.mode' => 'central', 'app.sync_back' => true]);
        $this->assertTrue($this->service->syncBack());
    }

    /** When central and SYNC_BACK=false (default), sync_back is false. */
    public function test_sync_back_returns_false_when_central_and_config_false(): void
    {
        config(['app.mode' => 'central', 'app.sync_back' => false]);
        $this->assertFalse($this->service->syncBack());
    }

    /** Bridge mode is only on edge; when EDGE_BRIDGE_MODE=false, bridgeModeEnabled is false. */
    public function test_bridge_mode_enabled_returns_false_when_edge_and_config_false(): void
    {
        config(['app.mode' => 'edge', 'app.edge_bridge_mode' => false]);
        $this->assertFalse($this->service->bridgeModeEnabled());
    }

    /** When edge and EDGE_BRIDGE_MODE=true, bridgeModeEnabled is true. */
    public function test_bridge_mode_enabled_returns_true_when_edge_and_config_true(): void
    {
        config(['app.mode' => 'edge', 'app.edge_bridge_mode' => true]);
        $this->assertTrue($this->service->bridgeModeEnabled());
    }

    /** On central, bridge mode is always false (bridge is edge-only). */
    public function test_bridge_mode_enabled_returns_false_when_central(): void
    {
        config(['app.mode' => 'central', 'app.edge_bridge_mode' => true]);
        $this->assertFalse($this->service->bridgeModeEnabled());
    }
}
