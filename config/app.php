<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'FlexiQueue'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Mode (central / edge)
    |--------------------------------------------------------------------------
    |
    | Single source of truth for whether this instance is a central server or
    | an edge Pi. Default is 'central' so existing deployments are unaffected.
    | All edge-mode checks must go through EdgeModeService, not config().
    |
    */

    'mode' => env('APP_MODE', 'central'),

    /*
    |--------------------------------------------------------------------------
    | Edge sync-back (central only)
    |--------------------------------------------------------------------------
    |
    | When true (central only), Site settings → Edge section is editable and
    | sync-back features are enabled. When false (always on edge; design: edge
    | does not sync back to central), the Edge section inputs and Save are
    | disabled. Set SYNC_BACK=false in env.edge on the Pi.
    |
    */

    'sync_back' => (bool) env('SYNC_BACK', false),

    /*
    |--------------------------------------------------------------------------
    | Edge bridge mode (edge only)
    |--------------------------------------------------------------------------
    |
    | When true on an edge Pi, Phase E bridge (proxy to central) can be active.
    | When false (default on edge), bridge is disabled; set EDGE_BRIDGE_MODE=false
    | in env.edge so any bridge-related UI or behavior stays off.
    |
    */

    'edge_bridge_mode' => (bool) env('EDGE_BRIDGE_MODE', false),

    /*
    |--------------------------------------------------------------------------
    | Edge central connection (edge Pi only)
    |--------------------------------------------------------------------------
    |
    | Used by edge:import-package and ImportProgramPackageJob. Must be in config
    | so they are available when config is cached on the Pi (env() is not read
    | after config:cache otherwise).
    |
    */

    'central_url' => env('CENTRAL_URL'),
    'central_api_key' => env('CENTRAL_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Demo mode (show seeded accounts on login page)
    |--------------------------------------------------------------------------
    | When DEMO=true, the login page shows a section with seeded demo accounts
    | (username + password hint) so staff can quickly sign in during demos.
    | When DEMO is false or unset, that section is hidden.
    |
    */

    'demo' => (bool) env('DEMO', false),

    'demo_accounts' => [
        ['label' => 'Admin (Tagudin)', 'username' => 'admin.tagudin', 'email' => 'admin@tagudinmswdo.gov.ph'],
        ['label' => 'Staff 1 (Tagudin)', 'username' => 'staff1.tagudin', 'email' => 'staff1@tagudinmswdo.gov.ph'],
        ['label' => 'Staff 2 (Tagudin)', 'username' => 'staff2.tagudin', 'email' => 'staff2@tagudinmswdo.gov.ph'],
        ['label' => 'Staff 3 (Tagudin)', 'username' => 'staff3.tagudin', 'email' => 'staff3@tagudinmswdo.gov.ph'],
        ['label' => 'Staff 4 (Tagudin)', 'username' => 'staff4.tagudin', 'email' => 'staff4@tagudinmswdo.gov.ph'],
        ['label' => 'Staff 5 (Tagudin)', 'username' => 'staff5.tagudin', 'email' => 'staff5@tagudinmswdo.gov.ph'],
        ['label' => 'Staff 6 (Tagudin)', 'username' => 'staff6.tagudin', 'email' => 'staff6@tagudinmswdo.gov.ph'],
        ['label' => 'Admin (Candon)', 'username' => 'admin.candon', 'email' => 'admin@candonmswdo.gov.ph'],
        ['label' => 'Staff 1 (Candon)', 'username' => 'staff1.candon', 'email' => 'staff1@candonmswdo.gov.ph'],
        ['label' => 'Staff 2 (Candon)', 'username' => 'staff2.candon', 'email' => 'staff2@candonmswdo.gov.ph'],
        ['label' => 'Staff 3 (Candon)', 'username' => 'staff3.candon', 'email' => 'staff3@candonmswdo.gov.ph'],
        ['label' => 'Staff 4 (Candon)', 'username' => 'staff4.candon', 'email' => 'staff4@candonmswdo.gov.ph'],
        ['label' => 'Staff 5 (Candon)', 'username' => 'staff5.candon', 'email' => 'staff5@candonmswdo.gov.ph'],
        ['label' => 'Staff 6 (Candon)', 'username' => 'staff6.candon', 'email' => 'staff6@candonmswdo.gov.ph'],
        ['label' => 'Admin (Edge)', 'username' => 'admin.field', 'email' => 'admin@tagudinfield.gov.ph'],
        ['label' => 'Staff 1 (Edge)', 'username' => 'staff1.field', 'email' => 'staff1@tagudinfield.gov.ph'],
        ['label' => 'Staff 2 (Edge)', 'username' => 'staff2.field', 'email' => 'staff2@tagudinfield.gov.ph'],
        ['label' => 'Staff 3 (Edge)', 'username' => 'staff3.field', 'email' => 'staff3@tagudinfield.gov.ph'],
        ['label' => 'Staff 4 (Edge)', 'username' => 'staff4.field', 'email' => 'staff4@tagudinfield.gov.ph'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

    /*
    |--------------------------------------------------------------------------
    | E2E Testing (Playwright / hyvor/laravel-playwright)
    |--------------------------------------------------------------------------
    | Prefix for testing endpoints. CAUTION: Only enabled in 'local' and 'testing'.
    */
    'e2e' => [
        'prefix' => 'playwright',
        'environments' => ['local', 'testing'],
    ],

];
