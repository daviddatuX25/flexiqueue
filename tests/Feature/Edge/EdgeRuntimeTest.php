<?php

namespace Tests\Feature\Edge;

use App\Models\EdgeDeviceState;
use App\Services\EdgeModeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EdgeRuntimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_returns_dev_by_default(): void
    {
        config(['app.edge_runtime' => null]);
        $service = new EdgeModeService;
        $this->assertSame('dev', $service->runtime());
    }

    public function test_runtime_returns_pi_when_EDGE_RUNTIME_is_pi(): void
    {
        config(['app.edge_runtime' => 'pi']);
        $service = new EdgeModeService;
        $this->assertSame('pi', $service->runtime());
    }

    public function test_runtime_returns_phone_when_EDGE_RUNTIME_is_phone(): void
    {
        config(['app.edge_runtime' => 'phone']);
        $service = new EdgeModeService;
        $this->assertSame('phone', $service->runtime());
    }

    public function test_runtime_returns_dev_for_invalid_value(): void
    {
        config(['app.edge_runtime' => 'invalid']);
        $service = new EdgeModeService;
        $this->assertSame('dev', $service->runtime());
    }

    public function test_edge_device_state_can_store_and_read_runtime(): void
    {
        $state = EdgeDeviceState::current();
        $state->update(['runtime' => 'phone']);
        $this->assertSame('phone', $state->fresh()->runtime);
    }

    public function test_edge_device_state_allows_null_runtime(): void
    {
        $state = EdgeDeviceState::current();
        $state->update(['runtime' => null]);
        $this->assertNull($state->fresh()->runtime);
    }
}
