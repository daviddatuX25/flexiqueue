<?php

namespace App\Providers;

use App\Models\Session;
use App\Models\Station;
use App\Policies\SessionPolicy;
use App\Policies\StationPolicy;
use App\Services\TtsService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TtsService::class, fn () => TtsService::fromConfig());
    }

    /**
     * Bootstrap any application services.
     * Per 05-SECURITY-CONTROLS §3.4: register RBAC policies.
     */
    public function boot(): void
    {
        Gate::policy(Station::class, StationPolicy::class);
        Gate::policy(Session::class, SessionPolicy::class);
    }
}
