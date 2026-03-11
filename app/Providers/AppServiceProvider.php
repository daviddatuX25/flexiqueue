<?php

namespace App\Providers;

use App\Events\StationDeleted;
use App\Events\TokenDeleted;
use App\Listeners\CleanupStationTtsFiles;
use App\Listeners\CleanupTokenTtsFiles;
use App\Models\Session;
use App\Models\Station;
use App\Policies\SessionPolicy;
use App\Policies\StationPolicy;
use App\Repositories\PrintSettingRepository;
use App\Repositories\TokenTtsSettingRepository;
use App\Services\TtsService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(TtsService::class, fn () => TtsService::fromConfig());
        $this->app->singleton(PrintSettingRepository::class);
        $this->app->singleton(TokenTtsSettingRepository::class);
    }

    /**
     * Bootstrap any application services.
     * Per 05-SECURITY-CONTROLS §3.4: register RBAC policies.
     */
    public function boot(): void
    {
        Gate::policy(Station::class, StationPolicy::class);
        Gate::policy(Session::class, SessionPolicy::class);

        Event::listen(StationDeleted::class, CleanupStationTtsFiles::class);
        Event::listen(TokenDeleted::class, CleanupTokenTtsFiles::class);
    }
}
