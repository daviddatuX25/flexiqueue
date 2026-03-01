<?php

use App\Models\Program;
use App\Models\Session;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Exit 0 if the app has an "open session" (active program or any active queue session).
 * Exit 1 if no session is open. Used by Pi script to start ZeroTier only when idle.
 * Per docs/architecture/10-DEPLOYMENT.md § ZeroTier timings.
 */
Artisan::command('flexiqueue:session-active', function () {
    $activeProgram = Program::where('is_active', true)->exists();
    $activeSessions = Session::active()->exists();
    $inSession = $activeProgram || $activeSessions;
    if ($inSession) {
        $this->comment('Session active (program or queue).');
        return 0;
    }
    $this->comment('No session active.');
    return 1;
})->purpose('Report if app has active program or queue sessions (exit 0=active, 1=idle).');
