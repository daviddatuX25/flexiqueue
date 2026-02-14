<?php

use App\Events\TestBroadcast;
use App\Http\Controllers\Admin\ProgramPageController;
use App\Http\Controllers\Api\Admin\ProgramController as AdminProgramController;
use App\Http\Controllers\Api\Admin\StationController as AdminStationController;
use App\Http\Controllers\Api\Admin\StepController as AdminStepController;
use App\Http\Controllers\Api\Admin\TokenController as AdminTokenController;
use App\Http\Controllers\Api\Admin\TrackController as AdminTrackController;
use App\Http\Controllers\Api\CheckStatusController;
use App\Http\Controllers\Api\SessionController as ApiSessionController;
use App\Http\Controllers\Api\StationController as ApiStationController;
use App\Http\Controllers\Api\VerifyPinController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DisplayController;
use App\Http\Controllers\StationPageController;
use App\Http\Controllers\TriagePageController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Per 08-API-SPEC-PHASE1 §5: admin API (session auth, role:admin)
Route::middleware(['auth', 'role:admin'])->prefix('api/admin')->group(function (): void {
    Route::get('/programs', [AdminProgramController::class, 'index']);
    Route::post('/programs', [AdminProgramController::class, 'store']);
    Route::get('/programs/{program}', [AdminProgramController::class, 'show']);
    Route::put('/programs/{program}', [AdminProgramController::class, 'update']);
    Route::post('/programs/{program}/activate', [AdminProgramController::class, 'activate'])->name('api.admin.programs.activate');
    Route::post('/programs/{program}/deactivate', [AdminProgramController::class, 'deactivate'])->name('api.admin.programs.deactivate');
    Route::delete('/programs/{program}', [AdminProgramController::class, 'destroy']);
    // Per 08-API-SPEC-PHASE1 §5.2: ServiceTrack CRUD
    Route::get('/programs/{program}/tracks', [AdminTrackController::class, 'index']);
    Route::post('/programs/{program}/tracks', [AdminTrackController::class, 'store']);
    Route::put('/tracks/{service_track}', [AdminTrackController::class, 'update']);
    Route::delete('/tracks/{service_track}', [AdminTrackController::class, 'destroy']);
    // Per 08-API-SPEC-PHASE1 §5.3: Station CRUD
    Route::get('/programs/{program}/stations', [AdminStationController::class, 'index']);
    Route::post('/programs/{program}/stations', [AdminStationController::class, 'store']);
    Route::put('/stations/{station}', [AdminStationController::class, 'update']);
    Route::delete('/stations/{station}', [AdminStationController::class, 'destroy']);
    // Per 08-API-SPEC-PHASE1 §5.4: Track Steps
    Route::get('/tracks/{track}/steps', [AdminStepController::class, 'index']);
    Route::post('/tracks/{track}/steps', [AdminStepController::class, 'store']);
    Route::post('/tracks/{track}/steps/reorder', [AdminStepController::class, 'reorder']);
    Route::put('/steps/{step}', [AdminStepController::class, 'update']);
    Route::delete('/steps/{step}', [AdminStepController::class, 'destroy']);
    // Per 08-API-SPEC-PHASE1 §5.5: Tokens
    Route::get('/tokens', [AdminTokenController::class, 'index']);
    Route::post('/tokens/batch', [AdminTokenController::class, 'batch']);
    Route::put('/tokens/{token}', [AdminTokenController::class, 'update']);
});

// Per 08-API-SPEC-PHASE1 §1.3: Supervisor PIN verification (any staff, rate limited)
Route::middleware(['auth', 'role:admin,supervisor,staff', 'throttle:5,1'])->group(function (): void {
    Route::post('/api/auth/verify-pin', VerifyPinController::class)->name('api.auth.verify-pin');
});

// Per 08-API-SPEC-PHASE1 §3–4: Session and station endpoints (any staff)
Route::middleware(['auth', 'role:admin,supervisor,staff'])->prefix('api')->group(function (): void {
    Route::post('/sessions/bind', [ApiSessionController::class, 'bind']);
    Route::get('/sessions/token-lookup', [ApiSessionController::class, 'tokenLookup']);
    Route::post('/sessions/{session}/call', [ApiSessionController::class, 'call']);
    Route::post('/sessions/{session}/transfer', [ApiSessionController::class, 'transfer']);
    Route::post('/sessions/{session}/complete', [ApiSessionController::class, 'complete']);
    Route::post('/sessions/{session}/cancel', [ApiSessionController::class, 'cancel']);
    Route::post('/sessions/{session}/no-show', [ApiSessionController::class, 'noShow']);
    Route::post('/sessions/{session}/force-complete', [ApiSessionController::class, 'forceComplete']);
    Route::post('/sessions/{session}/override', [ApiSessionController::class, 'override']);
    // Per 08-API-SPEC-PHASE1 §4: Station queue API
    Route::get('/stations', [ApiStationController::class, 'index']);
    Route::get('/stations/{station}/queue', [ApiStationController::class, 'queue']);
});

// Per 05-SECURITY-CONTROLS §2.4: public routes (no auth)
Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login')->middleware('guest');
Route::post('/login', [LoginController::class, 'login'])->middleware('guest');
Route::post('/logout', [LoginController::class, 'logout'])->name('logout')->middleware('auth');

// Per 08-API-SPEC-PHASE1 §2.1 & 05-SECURITY-CONTROLS §2.4: public (no auth)
Route::get('/api/check-status/{qr_hash}', [CheckStatusController::class, 'show']);

// Per 09-UI-ROUTES: client-facing informant display (no auth)
Route::get('/display', [DisplayController::class, 'board'])->name('display');
Route::get('/display/status/{qr_hash}', [DisplayController::class, 'status'])->name('display.status');

// Per 05-SECURITY-CONTROLS §3.4: admin-only routes
Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/dashboard', fn () => Inertia::render('Admin/Dashboard'))->name('dashboard');
    Route::get('/programs', [ProgramPageController::class, 'index'])->name('programs');
    Route::get('/programs/{program}', [ProgramPageController::class, 'show'])->name('programs.show');
    Route::get('/tokens', fn () => Inertia::render('Admin/Tokens/Index'))->name('tokens');
    Route::get('/users', fn () => Inertia::render('Admin/Users/Index'))->name('users');
    Route::get('/reports', fn () => Inertia::render('Admin/Reports/Index'))->name('reports');
});

// All staff (admin, supervisor, staff): station and triage
Route::middleware(['auth', 'role:admin,supervisor,staff'])->group(function (): void {
    Route::get('/station/{station?}', StationPageController::class)->name('station');
    Route::get('/triage', TriagePageController::class)->name('triage');
});

// All other web routes require authentication
Route::middleware('auth')->group(function (): void {
    Route::get('/', function () {
        return Inertia::render('Welcome', [
            'appName' => config('app.name'),
        ]);
    });

    // BD-002: Test broadcast page and trigger route
    Route::get('/broadcast-test', function () {
        return Inertia::render('BroadcastTest');
    })->name('broadcast-test');

    Route::post('/broadcast-test', function () {
        TestBroadcast::dispatch(
            'Hello from Reverb at '.now()->toIso8601String(),
            now()->toIso8601String()
        );

        return response()->json(['ok' => true]);
    })->name('broadcast-test.fire');
});
