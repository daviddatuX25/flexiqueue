<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use App\Services\EdgeModeService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Verifies that EDGE_RUNTIME=phone causes AppServiceProvider to skip SQLCipher PRAGMA.
 * On phone (Termux) there is no SQLCipher extension — plain SQLite only.
 *
 * AppServiceProvider::boot() guards the PRAGMA registration:
 *   - If app.mode !== 'edge' -> skip entirely
 *   - If edge + runtime=phone -> early return (no DB::afterConnecting registered)
 *   - If edge + runtime=pi   -> register DB::afterConnecting with PRAGMA key
 *
 * Uses DB::spy() to assert whether afterConnecting was called. No RefreshDatabase
 * needed because we never open a real database connection.
 */
class EdgeRuntimePhoneTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // EdgeModeService is a singleton — replace with a fresh instance
        // each test so it picks up the new config values.
        $this->app->instance(EdgeModeService::class, new EdgeModeService);
    }

    public function test_sqlcipher_pragma_skipped_when_phone_runtime(): void
    {
        config(['app.mode' => 'edge', 'app.edge_runtime' => 'phone']);

        $service = app(EdgeModeService::class);
        $this->assertSame('phone', $service->runtime(), 'Precondition: runtime must be phone');

        DB::spy();

        (new AppServiceProvider($this->app))->boot();

        // The provider should NOT call afterConnecting when runtime is phone.
        DB::shouldNotHaveReceived('afterConnecting');
    }

    public function test_sqlcipher_pragma_applied_when_pi_runtime(): void
    {
        config(['app.mode' => 'edge', 'app.edge_runtime' => 'pi']);

        $service = app(EdgeModeService::class);
        $this->assertSame('pi', $service->runtime(), 'Precondition: runtime must be pi');

        DB::spy();

        (new AppServiceProvider($this->app))->boot();

        // The provider SHOULD call afterConnecting when runtime is pi.
        DB::shouldHaveReceived('afterConnecting')->once();
    }

    public function test_sqlcipher_pragma_skipped_when_central_mode(): void
    {
        config(['app.mode' => 'central', 'app.edge_runtime' => 'pi']);

        DB::spy();

        (new AppServiceProvider($this->app))->boot();

        // In central mode, the provider should skip the entire edge block.
        DB::shouldNotHaveReceived('afterConnecting');
    }
}