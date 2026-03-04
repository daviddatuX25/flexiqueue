<?php

use App\Events\TestBroadcast;
use App\Http\Controllers\Admin\ProgramPageController;
use App\Http\Controllers\Api\Admin\ProgramController as AdminProgramController;
use App\Http\Controllers\Api\Admin\ProgramStaffController;
use App\Http\Controllers\Api\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Api\Admin\StationController as AdminStationController;
use App\Http\Controllers\Api\Admin\ProcessController as AdminProcessController;
use App\Http\Controllers\Api\Admin\ProgramDiagramController;
use App\Http\Controllers\Api\Admin\StepController as AdminStepController;
use App\Http\Controllers\Api\Admin\PrintSettingsController;
use App\Http\Controllers\Api\Admin\ProgramDefaultSettingsController;
use App\Http\Controllers\Api\Admin\TokenController as AdminTokenController;
use App\Http\Controllers\Api\Admin\TrackController as AdminTrackController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\CheckStatusController;
use App\Http\Controllers\Api\PublicDisplaySettingsController;
use App\Http\Controllers\Api\PublicTriageController;
use App\Http\Controllers\Api\SessionController as ApiSessionController;
use App\Http\Controllers\Api\TtsController;
use App\Http\Controllers\Api\PermissionRequestController;
use App\Http\Controllers\Api\StationController as ApiStationController;
use App\Http\Controllers\Api\StationNoteController;
use App\Http\Controllers\Api\AuthorizationsController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\UserAvailabilityController;
use App\Http\Controllers\Api\TemporaryPinController;
use App\Http\Controllers\Api\TemporaryQrController;
use App\Http\Controllers\Api\VerifyPinController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Admin\ReportPageController;
use App\Http\Controllers\Admin\TokenPrintController;
use App\Http\Controllers\Admin\UserPageController;
use App\Http\Controllers\DisplayController;
use App\Http\Controllers\AuthorizationPageController;
use App\Http\Controllers\StationPageController;
use App\Http\Controllers\TriagePageController;
use App\Enums\UserRole;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Per 08-API-SPEC-PHASE1 §5: admin API (session auth, role:admin)
Route::middleware(['auth', 'role:admin'])->prefix('api/admin')->group(function (): void {
    Route::get('/program-default-settings', [ProgramDefaultSettingsController::class, 'show']);
    Route::put('/program-default-settings', [ProgramDefaultSettingsController::class, 'update']);
    Route::get('/programs', [AdminProgramController::class, 'index']);
    Route::post('/programs', [AdminProgramController::class, 'store']);
    Route::get('/programs/{program}', [AdminProgramController::class, 'show']);
    Route::put('/programs/{program}', [AdminProgramController::class, 'update']);
    Route::get('/programs/{program}/diagram', [ProgramDiagramController::class, 'show']);
    Route::put('/programs/{program}/diagram', [ProgramDiagramController::class, 'update']);
    Route::post('/programs/{program}/diagram/image', [ProgramDiagramController::class, 'storeImage']);
    Route::post('/programs/{program}/activate', [AdminProgramController::class, 'activate'])->name('api.admin.programs.activate');
    Route::post('/programs/{program}/deactivate', [AdminProgramController::class, 'deactivate'])->name('api.admin.programs.deactivate');
    Route::post('/programs/{program}/pause', [AdminProgramController::class, 'pause'])->name('api.admin.programs.pause');
    Route::post('/programs/{program}/resume', [AdminProgramController::class, 'resume'])->name('api.admin.programs.resume');
    Route::delete('/programs/{program}', [AdminProgramController::class, 'destroy']);
    // Per 08-API-SPEC-PHASE1 §5.2: ServiceTrack CRUD
    Route::get('/programs/{program}/tracks', [AdminTrackController::class, 'index']);
    Route::post('/programs/{program}/tracks', [AdminTrackController::class, 'store']);
    Route::put('/tracks/{service_track}', [AdminTrackController::class, 'update']);
    Route::delete('/tracks/{service_track}', [AdminTrackController::class, 'destroy']);
    // Per 08-API-SPEC-PHASE1 §5.3: Station CRUD
    Route::get('/programs/{program}/processes', [AdminProcessController::class, 'index']);
    Route::post('/programs/{program}/processes', [AdminProcessController::class, 'store']);
    Route::put('/programs/{program}/processes/{process}', [AdminProcessController::class, 'update']);
    Route::delete('/programs/{program}/processes/{process}', [AdminProcessController::class, 'destroy']);
    Route::get('/programs/{program}/stations', [AdminStationController::class, 'index']);
    Route::post('/programs/{program}/stations', [AdminStationController::class, 'store']);
    Route::get('/programs/{program}/stations/{station}/processes', [AdminStationController::class, 'listProcesses']);
    Route::put('/programs/{program}/stations/{station}/processes', [AdminStationController::class, 'setProcesses']);
    Route::put('/stations/{station}', [AdminStationController::class, 'update']);
    Route::delete('/stations/{station}', [AdminStationController::class, 'destroy']);
    // Per 08-API-SPEC-PHASE1 §5.4: Track Steps
    Route::get('/tracks/{track}/steps', [AdminStepController::class, 'index']);
    Route::post('/tracks/{track}/steps', [AdminStepController::class, 'store']);
    Route::post('/tracks/{track}/steps/reorder', [AdminStepController::class, 'reorder']);
    Route::put('/steps/{step}', [AdminStepController::class, 'update']);
    Route::delete('/steps/{step}', [AdminStepController::class, 'destroy']);
    // Per refactor plan: program-scoped staff assignments and supervisors
    Route::get('/programs/{program}/staff-assignments', [ProgramStaffController::class, 'staffAssignments']);
    Route::post('/programs/{program}/staff-assignments', [ProgramStaffController::class, 'assignStaff']);
    Route::delete('/programs/{program}/staff-assignments/{user}', [ProgramStaffController::class, 'unassignStaff']);
    Route::get('/programs/{program}/supervisors', [ProgramStaffController::class, 'supervisors']);
    Route::post('/programs/{program}/supervisors', [ProgramStaffController::class, 'addSupervisor']);
    Route::delete('/programs/{program}/supervisors/{user}', [ProgramStaffController::class, 'removeSupervisor']);
    // Per 08-API-SPEC-PHASE1 §5.5: Tokens
    Route::get('/tokens', [AdminTokenController::class, 'index']);
    Route::post('/tokens/batch', [AdminTokenController::class, 'batch']);
    Route::put('/tokens/{token}', [AdminTokenController::class, 'update']);
    Route::delete('/tokens/{token}', [AdminTokenController::class, 'destroy']);
    Route::post('/tokens/batch-delete', [AdminTokenController::class, 'batchDelete']);
    // Print template settings
    Route::get('/print-settings', [PrintSettingsController::class, 'show']);
    Route::put('/print-settings', [PrintSettingsController::class, 'update']);
    // Per 08-API-SPEC-PHASE1 §5.6, §5.7: User CRUD and staff assignment
    Route::get('/users', [AdminUserController::class, 'index']);
    Route::post('/users', [AdminUserController::class, 'store']);
    Route::put('/users/{user}', [AdminUserController::class, 'update']);
    Route::delete('/users/{user}', [AdminUserController::class, 'destroy']);
    Route::post('/users/{user}/reset-password', [AdminUserController::class, 'resetPassword']);
    Route::post('/users/{user}/assign-station', [AdminUserController::class, 'assignStation']);
    Route::post('/users/{user}/unassign-station', [AdminUserController::class, 'unassignStation']);
    // Per 08-API-SPEC-PHASE1 §5.8: Reports
    Route::get('/reports/program-sessions', [AdminReportController::class, 'programSessions']);
    Route::get('/reports/audit', [AdminReportController::class, 'audit']);
    Route::get('/reports/audit/export', [AdminReportController::class, 'auditExport']);
});

// Per 08-API-SPEC-PHASE1 §6.1: Dashboard API (admin, supervisor)
Route::middleware(['auth', 'role:admin,supervisor'])->prefix('api/dashboard')->group(function (): void {
    Route::get('/stats', [DashboardController::class, 'stats']);
    Route::get('/stations', [DashboardController::class, 'stations']);
});

// Per 08-API-SPEC-PHASE1 §1.3: Supervisor PIN verification (any staff, rate limited)
Route::middleware(['auth', 'role:admin,supervisor,staff', 'throttle:5,1'])->group(function (): void {
    Route::post('/api/auth/verify-pin', VerifyPinController::class)->name('api.auth.verify-pin');
});

// Per staff-availability-status plan: PATCH /api/users/me/availability (any authenticated staff)
Route::middleware('auth')->group(function (): void {
    Route::patch('/api/users/me/availability', [UserAvailabilityController::class, 'update'])->name('api.users.me.availability');
});

// Per PIN-QR-AUTHORIZATION-SYSTEM AUTH-2: Profile preset PIN/QR (authenticated user only; admin cannot view)
Route::middleware('auth')->prefix('api/profile')->group(function (): void {
    Route::put('/override-pin', [ProfileController::class, 'updateOverridePin'])->name('api.profile.override-pin');
    Route::get('/override-qr', [ProfileController::class, 'showOverrideQr'])->name('api.profile.override-qr');
    Route::post('/override-qr/regenerate', [ProfileController::class, 'regenerateOverrideQr'])->name('api.profile.override-qr.regenerate');
    Route::put('/password', [ProfileController::class, 'updatePassword'])->name('api.profile.password');
    Route::post('/avatar', [ProfileController::class, 'updateAvatar'])->name('api.profile.avatar');
});

// Per PIN-QR-AUTHORIZATION-SYSTEM AUTH-3, AUTH-4: Temporary PIN/QR generation (supervisor/admin only)
// Per TRACK-OVERRIDES-REFACTOR §1.4: List generated authorizations
Route::middleware(['auth', 'role:admin,supervisor'])->group(function (): void {
    Route::get('/api/auth/authorizations', [AuthorizationsController::class, 'index'])->name('api.auth.authorizations');
    Route::post('/api/auth/temporary-pin', TemporaryPinController::class)->name('api.auth.temporary-pin');
    Route::post('/api/auth/temporary-qr', TemporaryQrController::class)->name('api.auth.temporary-qr');
});

// Permission requests (any staff create; supervisor/admin approve/reject)
Route::middleware(['auth', 'role:admin,supervisor,staff'])->prefix('api')->group(function (): void {
    Route::post('/permission-requests', [PermissionRequestController::class, 'store']);
    Route::post('/permission-requests/{permission_request}/approve', [PermissionRequestController::class, 'approve']);
    Route::post('/permission-requests/{permission_request}/reject', [PermissionRequestController::class, 'reject']);
});

// Per 08-API-SPEC-PHASE1 §3–4: Session and station endpoints (any staff)
Route::middleware(['auth', 'role:admin,supervisor,staff'])->prefix('api')->group(function (): void {
    Route::post('/sessions/bind', [ApiSessionController::class, 'bind']);
    Route::get('/sessions/token-lookup', [ApiSessionController::class, 'tokenLookup']);
    Route::post('/sessions/{session}/call', [ApiSessionController::class, 'call']);
    Route::post('/sessions/{session}/serve', [ApiSessionController::class, 'serve']);
    Route::post('/sessions/{session}/transfer', [ApiSessionController::class, 'transfer']);
    Route::post('/sessions/{session}/complete', [ApiSessionController::class, 'complete']);
    Route::post('/sessions/{session}/cancel', [ApiSessionController::class, 'cancel']);
    Route::post('/sessions/{session}/no-show', [ApiSessionController::class, 'noShow']);
    Route::post('/sessions/{session}/force-complete', [ApiSessionController::class, 'forceComplete']);
    Route::post('/sessions/{session}/override', [ApiSessionController::class, 'override']);
    // Per 08-API-SPEC-PHASE1 §4: Station queue API
    Route::get('/stations', [ApiStationController::class, 'index']);
    Route::get('/stations/{station}/queue', [ApiStationController::class, 'queue']);
    Route::post('/stations/{station}/priority-first', [ApiStationController::class, 'setPriorityFirst']);
    Route::get('/stations/{station}/notes', [StationNoteController::class, 'show']);
    Route::put('/stations/{station}/notes', [StationNoteController::class, 'update']);
    Route::put('/stations/{station}/display-settings', [ApiStationController::class, 'updateDisplaySettings']);
});

// Per 05-SECURITY-CONTROLS §2.4: public routes (no auth)
Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login')->middleware('guest');
Route::post('/login', [LoginController::class, 'login'])->middleware('guest');
Route::post('/logout', [LoginController::class, 'logout'])->name('logout')->middleware('auth');

// Per 08-API-SPEC-PHASE1 §2.1 & 05-SECURITY-CONTROLS §2.4: public (no auth)
Route::get('/api/check-status/{qr_hash}', [CheckStatusController::class, 'show']);

// Per 09-UI-ROUTES: client-facing informant display (no auth)
Route::get('/display', [DisplayController::class, 'board'])->name('display');
Route::get('/display/station/{station}', [DisplayController::class, 'stationBoard'])->name('display.station');
Route::get('/display/status/{qr_hash}', [DisplayController::class, 'status'])->name('display.status');

// Per plan: public self-serve triage (no auth; 403 when program allow_public_triage is false)
Route::get('/triage/start', [DisplayController::class, 'publicTriage'])->name('triage.start');
Route::get('/api/public/token-lookup', [PublicTriageController::class, 'tokenLookup']);
Route::post('/api/public/sessions/bind', [PublicTriageController::class, 'bind']);
// Per plan: public display/triage settings (PIN required); rate limit 10/min by IP
Route::post('/api/public/display-settings', [PublicDisplaySettingsController::class, 'update'])
    ->middleware('throttle:10,1');
// Per plan: server-side TTS — stream audio (public, rate-limited); voices list for admin
Route::get('/api/public/tts', [TtsController::class, 'stream'])->middleware('throttle:60,1');
Route::get('/api/public/tts/voices', [TtsController::class, 'voices']);
Route::get('/api/public/tts/token/{token}', [TtsController::class, 'token']);

// Per 05-SECURITY-CONTROLS §3.4: admin-only routes
Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::redirect('/', '/admin/dashboard', 302)->name('index');
    Route::get('/dashboard', fn () => Inertia::render('Admin/Dashboard'))->name('dashboard');
    Route::get('/programs', [ProgramPageController::class, 'index'])->name('programs');
    Route::get('/program-default-settings', fn () => Inertia::render('Admin/ProgramDefaultSettings'))->name('program-default-settings');
    Route::get('/programs/{program}', [ProgramPageController::class, 'show'])->name('programs.show');
    Route::get('/tokens', fn () => Inertia::render('Admin/Tokens/Index'))->name('tokens');
    Route::get('/tokens/print', TokenPrintController::class)->name('tokens.print');
    Route::get('/users', [UserPageController::class, 'index'])->name('users');
    Route::get('/reports', [ReportPageController::class, 'index'])->name('reports');
});

// All staff (admin, supervisor, staff): station, triage, track-overrides, profile, dashboard
Route::middleware(['auth', 'role:admin,supervisor,staff'])->group(function (): void {
    Route::get('/dashboard', \App\Http\Controllers\StaffDashboardController::class)->name('dashboard');
    Route::get('/station/{station?}', StationPageController::class)->name('station');
    Route::get('/triage', TriagePageController::class)->name('triage');
    Route::redirect('/authorize', '/track-overrides', 302)->name('authorize');
    Route::get('/track-overrides', AuthorizationPageController::class)->name('track-overrides');
    Route::get('/profile', fn () => Inertia::render('Profile/Index'))->name('profile');
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
