<?php

namespace App\Providers;

use App\Events\StationDeleted;
use App\Events\TokenDeleted;
use App\Listeners\CleanupStationTtsFiles;
use App\Listeners\CleanupTokenTtsFiles;
use App\Models\Program;
use App\Models\Session;
use App\Models\Site;
use App\Models\Station;
use App\Observers\ProgramObserver;
use App\Observers\SiteObserver;
use App\Policies\SessionPolicy;
use App\Policies\StationPolicy;
use App\Repositories\PrintSettingRepository;
use App\Repositories\TokenTtsSettingRepository;
use App\Services\EdgeModeService;
use App\Services\Tts\Contracts\TtsEngine;
use App\Services\Tts\Engines\ElevenLabsEngine;
use App\Services\Tts\Engines\NullTtsEngine;
use App\Services\TtsService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
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
        $this->app->bind(TtsEngine::class, function ($app) {
            $driver = config('tts.driver', 'null');

            return match ($driver) {
                'elevenlabs' => $app->make(ElevenLabsEngine::class),
                default => $app->make(NullTtsEngine::class),
            };
        });

        $this->app->bind(TtsService::class, fn () => TtsService::fromConfig());
        $this->app->singleton(EdgeModeService::class);
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

        Site::observe(SiteObserver::class);
        Program::observe(ProgramObserver::class);

        Event::listen(StationDeleted::class, CleanupStationTtsFiles::class);
        Event::listen(TokenDeleted::class, CleanupTokenTtsFiles::class);

        // Per HYBRID_AUTH_ADMIN_FIRST_PRD.md: reset link identifies account by username (not users.email).
        ResetPassword::createUrlUsing(function ($user, string $token): string {
            return url(route('password.reset', [
                'token' => $token,
                'username' => $user->username,
            ], false));
        });

        if (config('app.mode') === 'edge') {
            DB::afterConnecting(static function (Connection $connection): void {
                if ($connection->getDriverName() === 'sqlite') {
                    $hexKey = self::deriveSqlCipherKey((string) config('app.key'));
                    $connection->statement("PRAGMA key = \"x'{$hexKey}'\";");
                }
            });
        }
    }

    /**
     * Derive a 64-char hex SQLCipher key from the Laravel app.key.
     * E11.2: SQLCipher requires a 256-bit (64 hex char) key derived via SHA-256.
     */
    public static function deriveSqlCipherKey(string $appKey): string
    {
        return hash('sha256', $appKey);
    }
}
