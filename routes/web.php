<?php

use App\Events\TestBroadcast;
use App\Http\Controllers\Admin\ClientPageController;
use App\Http\Controllers\Admin\DashboardPageController;
use App\Http\Controllers\Admin\ProgramPageController;
use App\Http\Controllers\Admin\ReportPageController;
use App\Http\Controllers\Admin\SitesPageController;
use App\Http\Controllers\Admin\TokenPrintController;
use App\Http\Controllers\Admin\UserPageController;
use App\Http\Controllers\Api\Admin\AnalyticsController as AdminAnalyticsController;
use App\Http\Controllers\Api\Admin\ClientAdminController;
use App\Http\Controllers\Api\Admin\EdgeDeviceController;
use App\Http\Controllers\Api\Admin\EdgeImportController;
use App\Http\Controllers\Api\Admin\ElevenLabsIntegrationController;
use App\Http\Controllers\Api\Admin\PermissionCatalogController;
use App\Http\Controllers\Api\Admin\PrintPlatformDefaultsController;
use App\Http\Controllers\Api\Admin\PrintSettingsController;
use App\Http\Controllers\Api\Admin\ProcessController as AdminProcessController;
use App\Http\Controllers\Api\Admin\ProgramAccessTokenController;
use App\Http\Controllers\Api\Admin\ProgramBannerImageController;
use App\Http\Controllers\Api\Admin\ProgramController as AdminProgramController;
use App\Http\Controllers\Api\Admin\ProgramDefaultSettingsController;
use App\Http\Controllers\Api\Admin\ProgramDiagramController;
use App\Http\Controllers\Api\Admin\ProgramPackageController;
use App\Http\Controllers\Api\Admin\ProgramPlatformDefaultSettingsController;
use App\Http\Controllers\Api\Admin\ProgramStaffController;
use App\Http\Controllers\Api\Admin\ProgramTokenController;
use App\Http\Controllers\Api\Admin\RbacTeamUserPermissionController;
use App\Http\Controllers\Api\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Api\Admin\ShortLinkController;
use App\Http\Controllers\Api\Admin\SiteController as AdminSiteController;
use App\Http\Controllers\Api\Admin\SiteHeroImageController;
use App\Http\Controllers\Api\Admin\SiteSettingsController;
use App\Http\Controllers\Api\Admin\StationController as AdminStationController;
use App\Http\Controllers\Api\Admin\StepController as AdminStepController;
use App\Http\Controllers\Api\Admin\SystemController as AdminSystemController;
use App\Http\Controllers\Api\Admin\TokenController as AdminTokenController;
use App\Http\Controllers\Api\Admin\TokenTtsSettingsController;
use App\Http\Controllers\Api\Admin\TrackController as AdminTrackController;
use App\Http\Controllers\Api\Admin\TtsBudgetController;
use App\Http\Controllers\Api\Admin\TtsPlatformBudgetController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\AuthorizationsController;
use App\Http\Controllers\Api\CheckStatusController;
use App\Http\Controllers\Api\ClientController as ApiClientController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DeviceAuthorizationRequestController;
use App\Http\Controllers\Api\DeviceAuthorizeController;
use App\Http\Controllers\Api\DeviceUnlockRequestController;
use App\Http\Controllers\Api\DisplaySettingsRequestController;
use App\Http\Controllers\Api\HomeStatsController;
use App\Http\Controllers\Api\IdentityRegistrationController;
use App\Http\Controllers\Api\PermissionRequestController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PublicDeviceAuthorizationRequestController;
use App\Http\Controllers\Api\PublicDeviceLockController;
use App\Http\Controllers\Api\PublicDeviceUnlockRequestController;
use App\Http\Controllers\Api\PublicDisplaySettingsController;
use App\Http\Controllers\Api\PublicDisplaySettingsRequestController;
use App\Http\Controllers\Api\PublicProgramKeyController;
use App\Http\Controllers\Api\PublicSiteKeyController;
use App\Http\Controllers\Api\PublicSiteStatsController;
use App\Http\Controllers\Api\PublicTriageController;
use App\Http\Controllers\Api\SessionController as ApiSessionController;
use App\Http\Controllers\Api\StaffTriageBindContextController;
use App\Http\Controllers\Api\StationController as ApiStationController;
use App\Http\Controllers\Api\StationNoteController;
use App\Http\Controllers\Api\TemporaryPinController;
use App\Http\Controllers\Api\TemporaryQrController;
use App\Http\Controllers\Api\TtsController;
use App\Http\Controllers\Api\UserAvailabilityController;
use App\Http\Controllers\Api\VerifyPinController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\DisplayController;
use App\Http\Controllers\EdgeSyncController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ProgramOverridesPageController;
use App\Http\Controllers\ShortLinkResolverController;
use App\Http\Controllers\StaffDashboardController;
use App\Http\Controllers\StationPageController;
use App\Http\Controllers\Edge\SetupController;
use App\Http\Controllers\TriagePageController;
use App\Models\UserCredential;
use App\Support\GoogleOAuthConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Per SUPER-ADMIN-VS-ADMIN-SPEC: admin-only API (programs, tokens, analytics, print/tts settings). Super_admin has no access.
Route::middleware(['auth', 'permission:admin.manage'])->prefix('api/admin')->group(function (): void {
    Route::get('/program-default-settings', [ProgramDefaultSettingsController::class, 'show']);
    Route::put('/program-default-settings', [ProgramDefaultSettingsController::class, 'update']);
    Route::get('/programs', [AdminProgramController::class, 'index']);
    Route::post('/programs', [AdminProgramController::class, 'store']);
    Route::get('/programs/{program}', [AdminProgramController::class, 'show']);
    Route::get('/programs/{program}/package', [ProgramPackageController::class, 'show']);
    Route::post('/edge/import', [EdgeImportController::class, 'trigger']);
    Route::get('/edge/import/status', [EdgeImportController::class, 'status']);
    Route::put('/programs/{program}', [AdminProgramController::class, 'update']);
    Route::get('/programs/{program}/diagram', [ProgramDiagramController::class, 'show']);
    Route::put('/programs/{program}/diagram', [ProgramDiagramController::class, 'update']);
    Route::post('/programs/{program}/diagram/image', [ProgramDiagramController::class, 'storeImage']);
    Route::post('/programs/{program}/generate-qr', [ShortLinkController::class, 'storeForProgram']);
    Route::get('/programs/{program}/access-tokens', [ProgramAccessTokenController::class, 'index']);
    Route::delete('/programs/{program}/access-tokens', [ProgramAccessTokenController::class, 'destroyAll']);
    Route::delete('/programs/{program}/access-tokens/{token}', [ProgramAccessTokenController::class, 'destroy']);
    Route::post('/programs/{program}/banner-image', [ProgramBannerImageController::class, 'store']);
    Route::delete('/programs/{program}/banner-image', [ProgramBannerImageController::class, 'destroy']);
    Route::post('/programs/{program}/regenerate-station-tts', [AdminProgramController::class, 'regenerateStationTts']);
    Route::post('/programs/{program}/activate', [AdminProgramController::class, 'activate'])->name('api.admin.programs.activate');
    Route::post('/programs/{program}/deactivate', [AdminProgramController::class, 'deactivate'])->name('api.admin.programs.deactivate');
    Route::post('/programs/{program}/pause', [AdminProgramController::class, 'pause'])->name('api.admin.programs.pause');
    Route::post('/programs/{program}/resume', [AdminProgramController::class, 'resume'])->name('api.admin.programs.resume');
    Route::delete('/programs/{program}', [AdminProgramController::class, 'destroy']);
    Route::get('/programs/{program}/tracks', [AdminTrackController::class, 'index']);
    Route::post('/programs/{program}/tracks', [AdminTrackController::class, 'store']);
    Route::put('/tracks/{service_track}', [AdminTrackController::class, 'update']);
    Route::delete('/tracks/{service_track}', [AdminTrackController::class, 'destroy']);
    Route::get('/programs/{program}/processes', [AdminProcessController::class, 'index']);
    Route::post('/programs/{program}/processes', [AdminProcessController::class, 'store']);
    Route::put('/programs/{program}/processes/{process}', [AdminProcessController::class, 'update']);
    Route::delete('/programs/{program}/processes/{process}', [AdminProcessController::class, 'destroy']);
    Route::get('/programs/{program}/stations', [AdminStationController::class, 'index']);
    Route::post('/programs/{program}/stations', [AdminStationController::class, 'store']);
    Route::get('/programs/{program}/stations/{station}/processes', [AdminStationController::class, 'listProcesses']);
    Route::put('/programs/{program}/stations/{station}/processes', [AdminStationController::class, 'setProcesses']);
    Route::put('/stations/{station}', [AdminStationController::class, 'update']);
    Route::post('/stations/{station}/regenerate-tts', [AdminStationController::class, 'regenerateTts']);
    Route::delete('/stations/{station}', [AdminStationController::class, 'destroy']);
    Route::get('/tracks/{track}/steps', [AdminStepController::class, 'index']);
    Route::post('/tracks/{track}/steps', [AdminStepController::class, 'store']);
    Route::post('/tracks/{track}/steps/reorder', [AdminStepController::class, 'reorder']);
    Route::put('/steps/{step}', [AdminStepController::class, 'update']);
    Route::delete('/steps/{step}', [AdminStepController::class, 'destroy']);
    Route::get('/programs/{program}/staff-assignments', [ProgramStaffController::class, 'staffAssignments']);
    Route::post('/programs/{program}/staff-assignments', [ProgramStaffController::class, 'assignStaff']);
    Route::delete('/programs/{program}/staff-assignments/{user}', [ProgramStaffController::class, 'unassignStaff']);
    Route::get('/programs/{program}/supervisors', [ProgramStaffController::class, 'supervisors']);
    Route::post('/programs/{program}/supervisors', [ProgramStaffController::class, 'addSupervisor']);
    Route::delete('/programs/{program}/supervisors/{user}', [ProgramStaffController::class, 'removeSupervisor']);
    Route::get('/programs/{program}/tokens', [ProgramTokenController::class, 'index']);
    Route::post('/programs/{program}/tokens/bulk', [ProgramTokenController::class, 'bulkStore']);
    Route::post('/programs/{program}/tokens', [ProgramTokenController::class, 'store']);
    Route::delete('/programs/{program}/tokens/{token}', [ProgramTokenController::class, 'destroy']);
    Route::get('/tokens', [AdminTokenController::class, 'index']);
    Route::post('/tokens/batch', [AdminTokenController::class, 'batch']);
    Route::put('/tokens/{token}', [AdminTokenController::class, 'update']);
    Route::delete('/tokens/{token}', [AdminTokenController::class, 'destroy']);
    Route::post('/tokens/batch-delete', [AdminTokenController::class, 'batchDelete']);
    Route::post('/tokens/regenerate-tts', [AdminTokenController::class, 'regenerateTts']);
    Route::get('/print-settings', [PrintSettingsController::class, 'show']);
    Route::put('/print-settings', [PrintSettingsController::class, 'update']);
    Route::get('/token-tts-settings', [TokenTtsSettingsController::class, 'show']);
    Route::put('/token-tts-settings', [TokenTtsSettingsController::class, 'update']);
    Route::get('/tts/sample-phrase', [TokenTtsSettingsController::class, 'samplePhrase']);
    Route::get('/tts/preview-text', [TokenTtsSettingsController::class, 'previewText']);
    Route::get('/tts/preview-token-spoken-part', [TokenTtsSettingsController::class, 'previewTokenSpokenPart']);
    Route::get('/tts/budget', [TtsBudgetController::class, 'index']);
    Route::post('/print-settings/image', [PrintSettingsController::class, 'upload']);
    Route::get('/analytics/summary', [AdminAnalyticsController::class, 'summary']);
    Route::get('/analytics/throughput', [AdminAnalyticsController::class, 'throughput']);
    Route::get('/analytics/wait-time-distribution', [AdminAnalyticsController::class, 'waitTimeDistribution']);
    Route::get('/analytics/station-utilization', [AdminAnalyticsController::class, 'stationUtilization']);
    Route::get('/analytics/tracks', [AdminAnalyticsController::class, 'tracks']);
    Route::get('/analytics/busiest-hours', [AdminAnalyticsController::class, 'busiestHours']);
    Route::get('/analytics/drop-off-funnel', [AdminAnalyticsController::class, 'dropOffFunnel']);
    Route::get('/analytics/token-tts-health', [AdminAnalyticsController::class, 'tokenTtsHealth']);
    // Deprecated: use PUT /api/admin/sites/{site} for site and settings.
    Route::patch('/site/settings', [SiteSettingsController::class, 'update'])->name('api.admin.site.settings');

    // Edge device management — E3, §20.2
    Route::get('/sites/{site}/edge-devices', [EdgeDeviceController::class, 'index'])->name('api.admin.edge-devices.index');
    Route::post('/sites/{site}/edge-devices/pairing-code', [EdgeDeviceController::class, 'generatePairingCode'])->name('api.admin.edge-devices.pairing-code');
    Route::put('/edge-devices/{device}', [EdgeDeviceController::class, 'update'])->name('api.admin.edge-devices.update');
    Route::delete('/edge-devices/{device}', [EdgeDeviceController::class, 'revoke'])->name('api.admin.edge-devices.revoke');
    Route::post('/edge-devices/{device}/dump', [EdgeDeviceController::class, 'dump'])->name('api.admin.edge-devices.dump');
    Route::post('/edge-devices/{device}/force-cancel', [EdgeDeviceController::class, 'forceCancel'])->name('api.admin.edge-devices.force-cancel');
});

// Per SUPER-ADMIN-VS-ADMIN-SPEC: integrations API is super_admin only.
Route::middleware(['auth', 'permission:platform.manage'])->prefix('api/admin')->group(function (): void {
    Route::get('/tts/platform-budget', [TtsPlatformBudgetController::class, 'show']);
    Route::put('/tts/platform-budget', [TtsPlatformBudgetController::class, 'update']);
    Route::get('/tts/budgets', [TtsBudgetController::class, 'indexAll']);
    Route::get('/integrations/elevenlabs', [ElevenLabsIntegrationController::class, 'show']);
    Route::get('/integrations/elevenlabs/usage', [ElevenLabsIntegrationController::class, 'usage']);
    Route::get('/integrations/elevenlabs/voices', [ElevenLabsIntegrationController::class, 'voices']);
    Route::get('/integrations/elevenlabs/accounts', [ElevenLabsIntegrationController::class, 'index']);
    Route::post('/integrations/elevenlabs/accounts', [ElevenLabsIntegrationController::class, 'store']);
    Route::put('/integrations/elevenlabs/accounts/{account}', [ElevenLabsIntegrationController::class, 'update']);
    Route::post('/integrations/elevenlabs/accounts/{account}/activate', [ElevenLabsIntegrationController::class, 'activate']);
    Route::delete('/integrations/elevenlabs/accounts/{account}', [ElevenLabsIntegrationController::class, 'destroy']);
    Route::get('/print-platform-default-settings', [PrintPlatformDefaultsController::class, 'show']);
    Route::put('/print-platform-default-settings', [PrintPlatformDefaultsController::class, 'update']);
    Route::post('/print-platform-default-settings/image', [PrintPlatformDefaultsController::class, 'upload']);
    Route::get('/program-platform-default-settings', [ProgramPlatformDefaultSettingsController::class, 'show']);
    Route::put('/program-platform-default-settings', [ProgramPlatformDefaultSettingsController::class, 'update']);
});

// Per 08-API-SPEC-PHASE1 §5: shared admin API (users, sites, system storage, logs, clients). Both admin and super_admin.
Route::middleware(['auth', 'permission:admin.shared'])->prefix('api/admin')->group(function (): void {
    Route::get('/permissions', [PermissionCatalogController::class, 'index']);
    Route::get('/users', [AdminUserController::class, 'index']);
    Route::post('/users', [AdminUserController::class, 'store']);
    Route::put('/users/{user}', [AdminUserController::class, 'update']);
    Route::delete('/users/{user}', [AdminUserController::class, 'destroy']);
    Route::post('/users/{user}/reset-password', [AdminUserController::class, 'resetPassword']);
    Route::post('/users/{user}/assign-station', [AdminUserController::class, 'assignStation']);
    Route::post('/users/{user}/unassign-station', [AdminUserController::class, 'unassignStation']);
    Route::get('/rbac-teams/{rbacTeam}/users/{user}', [RbacTeamUserPermissionController::class, 'show']);
    Route::put('/rbac-teams/{rbacTeam}/users/{user}', [RbacTeamUserPermissionController::class, 'update']);
    Route::put('/clients/{client}', [ClientAdminController::class, 'update']);
    Route::delete('/clients/{client}', [ClientAdminController::class, 'destroy']);
    Route::get('/system/storage', [AdminSystemController::class, 'storage']);
    Route::post('/system/storage/clear', [AdminSystemController::class, 'clearStorage']);
    Route::post('/system/storage/clear-orphaned-tts', [AdminSystemController::class, 'clearOrphanedTts']);
    Route::get('/logs/program-sessions', [AdminReportController::class, 'programSessions']);
    Route::get('/logs/audit', [AdminReportController::class, 'audit']);
    Route::get('/logs/audit/export', [AdminReportController::class, 'auditExport']);
    Route::get('/sites', [AdminSiteController::class, 'index']);
    Route::post('/sites', [AdminSiteController::class, 'store']);
    Route::get('/sites/{site}', [AdminSiteController::class, 'show']);
    Route::get('/sites/{site}/tts-budget', [TtsBudgetController::class, 'show']);
    Route::put('/sites/{site}', [AdminSiteController::class, 'update']);
    Route::patch('/sites/{site}/default', [AdminSiteController::class, 'setDefault'])->name('api.admin.sites.set-default');
    Route::post('/sites/{site}/regenerate-key', [AdminSiteController::class, 'regenerateKey']);
    Route::post('/sites/{site}/hero-image', [SiteHeroImageController::class, 'store']);
    Route::delete('/sites/{site}/hero-image', [SiteHeroImageController::class, 'destroy']);
    Route::post('/sites/{site}/generate-qr', [ShortLinkController::class, 'storeForSite']);
});

// Per 08-API-SPEC-PHASE1 §6.1: Dashboard API (admin, supervisor)
Route::middleware(['auth', 'permission:dashboard.view'])->prefix('api/dashboard')->group(function (): void {
    Route::get('/stats', [DashboardController::class, 'stats']);
    Route::get('/stations', [DashboardController::class, 'stations']);
});

// Per 08-API-SPEC-PHASE1 §1.3: Supervisor PIN verification (any staff, rate limited)
Route::middleware(['auth', 'permission:staff.operations', 'throttle:5,1'])->group(function (): void {
    Route::post('/api/auth/verify-pin', VerifyPinController::class)->name('api.auth.verify-pin');
});

// Per staff-availability-status plan: PATCH /api/users/me/availability (any authenticated staff)
Route::middleware(['auth', 'permission:profile.self'])->group(function (): void {
    Route::patch('/api/users/me/availability', [UserAvailabilityController::class, 'update'])->name('api.users.me.availability');
});

// Per PIN-QR-AUTHORIZATION-SYSTEM AUTH-2: Profile preset PIN/QR (authenticated user only; admin cannot view)
Route::middleware(['auth', 'permission:profile.self'])->prefix('api/profile')->group(function (): void {
    Route::put('/override-pin', [ProfileController::class, 'updateOverridePin'])->name('api.profile.override-pin');
    Route::get('/override-qr', [ProfileController::class, 'showOverrideQr'])->name('api.profile.override-qr');
    Route::post('/override-qr/regenerate', [ProfileController::class, 'regenerateOverrideQr'])->name('api.profile.override-qr.regenerate');
    Route::put('/password', [ProfileController::class, 'updatePassword'])->name('api.profile.password');
    Route::post('/avatar', [ProfileController::class, 'updateAvatar'])->name('api.profile.avatar');
    Route::get('/triage-settings', [ProfileController::class, 'triageSettings'])->name('api.profile.triage-settings');
    Route::put('/triage-settings', [ProfileController::class, 'updateTriageSettings'])->name('api.profile.triage-settings.update');
    Route::delete('/google', [ProfileController::class, 'unlinkGoogle'])->name('api.profile.google.unlink');
});

// Per PIN-QR-AUTHORIZATION-SYSTEM AUTH-3, AUTH-4: Temporary PIN/QR generation (supervisor/admin only)
// Per TRACK-OVERRIDES-REFACTOR §1.4: List and manage generated authorizations
Route::middleware(['auth', 'permission:auth.supervisor_tools'])->group(function (): void {
    Route::get('/api/auth/authorizations', [AuthorizationsController::class, 'index'])->name('api.auth.authorizations');
    Route::delete('/api/auth/authorizations/{authorization}', [AuthorizationsController::class, 'destroy'])->name('api.auth.authorizations.destroy');
    Route::post('/api/auth/temporary-pin', TemporaryPinController::class)->name('api.auth.temporary-pin');
    Route::post('/api/auth/temporary-qr', TemporaryQrController::class)->name('api.auth.temporary-qr');
});

// Permission requests (any staff create; supervisor/admin approve/reject)
Route::middleware(['auth', 'permission:staff.operations'])->prefix('api')->group(function (): void {
    Route::post('/permission-requests', [PermissionRequestController::class, 'store']);
    Route::post('/permission-requests/{permission_request}/approve', [PermissionRequestController::class, 'approve']);
    Route::post('/permission-requests/{permission_request}/reject', [PermissionRequestController::class, 'reject']);
    Route::post('/permission-requests/{permission_request}/cancel', [PermissionRequestController::class, 'cancel']);
    Route::post('/display-settings-requests/{display_settings_request}/approve', [DisplaySettingsRequestController::class, 'approve']);
    Route::post('/device-authorization-requests/{device_authorization_request}/approve', [DeviceAuthorizationRequestController::class, 'approve']);
    Route::post('/device-unlock-requests/{device_unlock_request}/approve', [DeviceUnlockRequestController::class, 'approve']);
});

// Per PRIVACY-BY-DESIGN-IDENTITY-BINDING: reveal-phone is admin only
Route::middleware(['auth', 'permission:admin.manage'])->prefix('api')->group(function (): void {
    Route::post('/clients/{client}/reveal-phone', [ApiClientController::class, 'revealPhone']);
});

// Edge-local sync trigger + status endpoints (E6.3) — runs ON the edge device, staff session auth
Route::middleware(['auth', 'permission:admin.manage'])->prefix('api/edge')->group(function (): void {
    Route::post('/sync-trigger', [\App\Http\Controllers\Edge\SyncTriggerController::class, 'trigger'])->name('api.edge.sync-trigger');
    Route::get('/sync-status', [\App\Http\Controllers\Edge\SyncTriggerController::class, 'status'])->name('api.edge.sync-status');
    Route::get('/sync-receipts', [\App\Http\Controllers\Edge\SyncTriggerController::class, 'receipts'])->name('api.edge.sync-receipts');
});

// Per 08-API-SPEC-PHASE1 §3–4: Session and station endpoints (any staff)
Route::middleware(['auth', 'permission:staff.operations'])->prefix('api')->group(function (): void {
    Route::get('/clients/search', [ApiClientController::class, 'search'])
        ->middleware('throttle:60,1');
    Route::post('/clients/search-by-phone', [ApiClientController::class, 'searchByPhone'])
        ->middleware('throttle:60,1');
    Route::post('/clients', [ApiClientController::class, 'store']);
    Route::put('/clients/{client}/mobile', [ApiClientController::class, 'updateMobile']);
    Route::get('/identity-registrations', [IdentityRegistrationController::class, 'index']);
    Route::post('/identity-registrations/direct', [IdentityRegistrationController::class, 'direct']);
    Route::get('/identity-registrations/{identityRegistration}/possible-matches', [IdentityRegistrationController::class, 'possibleMatches']);
    Route::post('/identity-registrations/{identityRegistration}/accept', [IdentityRegistrationController::class, 'accept']);
    Route::post('/identity-registrations/{identityRegistration}/reveal-phone', [IdentityRegistrationController::class, 'revealPhone']);
    Route::post('/identity-registrations/{identityRegistration}/reject', [IdentityRegistrationController::class, 'reject']);
    Route::post('/identity-registrations/{identityRegistration}/confirm-bind', [IdentityRegistrationController::class, 'confirmBind']);
    Route::post('/sessions/bind', [ApiSessionController::class, 'bind']);
    Route::get('/sessions/token-lookup', [ApiSessionController::class, 'tokenLookup']);
    Route::get('/staff/triage-bind-context', [StaffTriageBindContextController::class, 'show']);
    Route::post('/sessions/{session}/call', [ApiSessionController::class, 'call']);
    Route::post('/sessions/{session}/serve', [ApiSessionController::class, 'serve']);
    Route::post('/sessions/{session}/transfer', [ApiSessionController::class, 'transfer']);
    Route::post('/sessions/{session}/complete', [ApiSessionController::class, 'complete']);
    Route::post('/sessions/{session}/cancel', [ApiSessionController::class, 'cancel']);
    Route::post('/sessions/{session}/hold', [ApiSessionController::class, 'hold']);
    Route::post('/sessions/{session}/resume-from-hold', [ApiSessionController::class, 'resumeFromHold']);
    Route::post('/sessions/{session}/enqueue-back', [ApiSessionController::class, 'enqueueBack']);
    Route::post('/sessions/{session}/no-show', [ApiSessionController::class, 'noShow']);
    Route::post('/sessions/{session}/force-complete', [ApiSessionController::class, 'forceComplete']);
    Route::post('/sessions/{session}/override', [ApiSessionController::class, 'override']);
    // Per 08-API-SPEC-PHASE1 §4: Station queue API
    Route::get('/stations', [ApiStationController::class, 'index']);
    Route::get('/stations/{station}/queue', [ApiStationController::class, 'queue']);
    Route::get('/stations/{station}/session-by-token', [ApiStationController::class, 'sessionByToken']);
    Route::post('/stations/{station}/priority-first', [ApiStationController::class, 'setPriorityFirst']);
    Route::get('/stations/{station}/notes', [StationNoteController::class, 'show']);
    Route::put('/stations/{station}/notes', [StationNoteController::class, 'update']);
    Route::put('/stations/{station}/display-settings', [ApiStationController::class, 'updateDisplaySettings']);
});

// Edge setup wizard (E2.4): one-time device pairing
Route::get('/edge/setup', [SetupController::class, 'show'])->name('edge.setup');
Route::post('/edge/setup', [SetupController::class, 'store'])->name('edge.setup.store');
Route::get('/edge/setup/ping-check', [SetupController::class, 'pingCheck'])->name('edge.setup.ping');

// Edge waiting page (E2.5): polls for assignment
Route::get('/edge/waiting', function () {
    return Inertia::render('Edge/Waiting');
})->name('edge.waiting');

// Local JSON status poll for the waiting page (no device token exposed to browser)
Route::get('/edge/waiting-status', function () {
    $state = \App\Models\EdgeDeviceState::current();
    return response()->json([
        'assigned'     => $state->active_program_id !== null,
        'program_name' => $state->active_program_name,
    ]);
})->name('edge.waiting.status');

// E9.4: revoked device terminal page
Route::get('/edge/revoked', function () {
    return Inertia::render('Edge/Revoked');
})->name('edge.revoked');

// Per 05-SECURITY-CONTROLS §2.4: public routes (no auth)
Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login')->middleware('guest');
Route::post('/login', [LoginController::class, 'login'])->middleware('guest');
Route::get('/forgot-password', [ForgotPasswordController::class, 'create'])->middleware(['guest', 'throttle:20,1'])->name('password.request');
Route::post('/forgot-password', [ForgotPasswordController::class, 'store'])->middleware(['guest', 'throttle:5,15'])->name('password.email');
Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->middleware(['guest', 'throttle:30,1'])->name('password.reset');
Route::post('/reset-password', [NewPasswordController::class, 'store'])->middleware(['guest', 'throttle:10,15'])->name('password.store');
Route::middleware(['not.edge'])->group(function () {
    Route::get('/auth/google', [GoogleAuthController::class, 'redirectToGoogle'])
        ->name('auth.google.redirect')
        ->middleware(['guest', 'throttle:20,1']);
    Route::get('/auth/google/link', [GoogleAuthController::class, 'redirectToLinkGoogle'])
        ->name('auth.google.link')
        ->middleware(['auth', 'permission:profile.self', 'throttle:10,1']);
    Route::get('/auth/google/callback', [GoogleAuthController::class, 'handleGoogleCallback'])
        ->name('auth.google.callback')
        ->middleware(['throttle:30,1']);
});
Route::post('/logout', [LoginController::class, 'logout'])->name('logout')->middleware('auth');

// Per HYBRID_AUTH_ADMIN_FIRST_PRD.md ONB-5 / H5: holding page until admin assigns station or clears pending_assignment.
Route::middleware(['auth', 'permission:profile.self'])->get('/pending-assignment', fn () => Inertia::render('PendingAssignment/Index'))->name('pending-assignment');

// Per central-edge B.2: sync/bridge auth stub (site API key only; no session)
Route::post('/api/sync/test-site-auth', function (Request $request) {
    $site = $request->attributes->get('site');
    if (! $site) {
        return response()->json(['message' => 'Site not bound.'], 500);
    }

    return response()->json(['site_id' => $site->id, 'slug' => $site->slug]);
})->middleware('site.api_key');

// Per docs/final-edge-mode-rush-plann.md [DF-07]: package export + TTS file stream for edge Pi
Route::middleware('site.api_key')->prefix('api/admin')->group(function (): void {
    Route::get('/programs/{program}/package', [ProgramPackageController::class, 'showForSite'])
        ->name('api.admin.programs.package.site');
    Route::get('/programs/{program}/tts-files/{filename}', [ProgramPackageController::class, 'streamTtsFile'])
        ->where('filename', '.+');
});

// Per 08-API-SPEC-PHASE1 §2.1 & 05-SECURITY-CONTROLS §2.4: public (no auth)
Route::get('/api/check-status/{qr_hash}', [CheckStatusController::class, 'show']);
Route::get('/api/check-status/{site_id}/{qr_hash}', [CheckStatusController::class, 'showWithSite']);

// Per public-site plan: homepage global stats (no auth, throttle 60/min)
Route::get('/api/home-stats', [HomeStatsController::class, 'index'])->middleware('throttle:60,1');

// Per public-site plan: site key validation (no auth, throttle 10/min per IP)
Route::post('/api/public/site-key', [PublicSiteKeyController::class, 'store'])->middleware('throttle:10,1');

// Per addition-to-public-site-plan Part 5.1: program key validation (no auth, throttle 10/min per IP)
Route::post('/api/public/program-key', [PublicProgramKeyController::class, 'store'])->middleware('throttle:10,1');

// Per public-site plan: site-scoped stats for landing (no auth, throttle 60/min)
Route::get('/api/public/site-stats/{site:slug}', [PublicSiteStatsController::class, 'show'])->middleware('throttle:60,1');

// Per addition-to-public-site-plan Part 6.2: opaque short link resolver (no auth)
Route::get('/go/{code}', [ShortLinkResolverController::class, 'resolve'])->name('short_link.resolve');

// Per 09-UI-ROUTES: client-facing informant display (no auth). Per public-site plan: no redirect by cookie; home/display always reachable.
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/display', [DisplayController::class, 'showScanQrMessage'])->name('display');
Route::get('/public-triage', [DisplayController::class, 'triageStartRedirect'])->name('public.triage');
Route::get('/display/station/{station}', [DisplayController::class, 'stationBoard'])->name('display.station');
Route::get('/display/status/{site_id}/{qr_hash}', [DisplayController::class, 'statusWithSite'])->name('display.status.site');
Route::get('/display/status/{qr_hash}', [DisplayController::class, 'status'])->name('display.status');

// Per public-site plan: site routes require known_sites cookie (key-gated). Public view + program info require program access when private.
Route::prefix('site')->middleware(['require.site.access'])->group(function (): void {
    Route::get('{site:slug}', [DisplayController::class, 'siteLanding'])->name('site.landing');
    Route::get('{site:slug}/program/{program_slug}/view', [DisplayController::class, 'publicDisplay'])
        ->middleware('require.program.access')->name('site.program.public-view');
    Route::get('{site:slug}/program/{program_slug}/info', [DisplayController::class, 'programInfo'])
        ->middleware('require.program.access')->name('site.program.info');
    Route::get('{site:slug}/display', [DisplayController::class, 'boardWithSite'])->name('display.site');
    Route::get('{site:slug}/display/station/{station}', [DisplayController::class, 'stationBoardWithSite'])->name('display.site.station');
    Route::get('{site:slug}/display/status/{qr_hash}', [DisplayController::class, 'statusWithSiteSlug'])->name('display.site.status');
    Route::get('{site:slug}/program/{program_slug}', [DisplayController::class, 'programPage'])
        ->middleware('require.program.access')->name('site.program');
    Route::get('{site:slug}/program/{program_slug}/devices', [DisplayController::class, 'chooseDeviceType'])
        ->middleware('require.program.access')->name('display.site.devices');
    Route::get('{site:slug}/public-triage', [DisplayController::class, 'triageStartWithSite'])->name('public.triage.site');
    Route::get('{site:slug}/public-triage/{program_slug}', [DisplayController::class, 'publicTriageWithSite'])->name('public.triage.site.program');
    Route::get('{site:slug}/kiosk', [DisplayController::class, 'kioskStartWithSite'])->name('public.kiosk.site');
    Route::get('{site:slug}/kiosk/{program_slug}', [DisplayController::class, 'publicKioskWithSite'])->name('public.kiosk.site.program');
});

// Per plan: public self-serve triage (no auth; 403 when program allow_public_triage is false)
// /public/triage/{program} kept for backward compat (program by id).
Route::get('/public/triage/{program}', [DisplayController::class, 'publicTriage'])->name('triage.public');
Route::get('/api/public/token-lookup', [PublicTriageController::class, 'tokenLookup']);
Route::post('/api/public/sessions/bind', [PublicTriageController::class, 'bind']);
Route::post('/api/public/verify-identity', [PublicTriageController::class, 'verifyIdentity'])->middleware('throttle:20,1');
// Per plan Step 5: device authorization (PIN/QR) for public display/triage
Route::post('/api/public/device-authorize', [DeviceAuthorizeController::class, 'store'])->middleware('throttle:20,1');
Route::post('/api/public/device-authorization-requests', [PublicDeviceAuthorizationRequestController::class, 'store'])->middleware('throttle:10,1');
Route::get('/api/public/device-authorization-requests/{id}', [PublicDeviceAuthorizationRequestController::class, 'show'])->middleware('throttle:30,1');
// Per plan: set device lock cookie after choosing device type (display/triage/station)
Route::post('/api/public/device-lock', [PublicDeviceLockController::class, 'store'])->middleware('throttle:30,1');
Route::post('/api/public/device-lock/clear', [PublicDeviceLockController::class, 'destroy'])->middleware('throttle:60,1');
Route::post('/api/public/device-unlock-with-auth', [PublicDeviceUnlockRequestController::class, 'unlockWithAuth'])->middleware('throttle:20,1');
Route::post('/api/public/device-unlock-requests', [PublicDeviceUnlockRequestController::class, 'store'])->middleware('throttle:10,1');
Route::get('/api/public/device-unlock-requests/{id}', [PublicDeviceUnlockRequestController::class, 'show'])->middleware('throttle:30,1');
Route::post('/api/public/device-unlock-requests/{id}/cancel', [PublicDeviceUnlockRequestController::class, 'cancel'])->middleware('throttle:30,1');
Route::post('/api/public/device-unlock-requests/{id}/consume', [PublicDeviceUnlockRequestController::class, 'consume'])->middleware('throttle:30,1');
// Per plan: public display/triage settings (PIN required); rate limit 10/min by IP
Route::post('/api/public/display-settings', [PublicDisplaySettingsController::class, 'update'])
    ->middleware('throttle:10,1');
Route::post('/api/public/display-settings-requests', [PublicDisplaySettingsRequestController::class, 'store'])
    ->middleware('throttle:10,1');
Route::get('/api/public/display-settings-requests/{id}', [PublicDisplaySettingsRequestController::class, 'show'])
    ->middleware('throttle:30,1');
Route::post('/api/public/display-settings-requests/{id}/cancel', [PublicDisplaySettingsRequestController::class, 'cancel'])
    ->middleware('throttle:30,1');
Route::post('/api/public/device-authorization-requests/{id}/cancel', [PublicDeviceAuthorizationRequestController::class, 'cancel'])
    ->middleware('throttle:30,1');
// Per TTS hardening: on-demand generation requires authenticated admin/super_admin + rate limit.
Route::get('/api/public/tts', [TtsController::class, 'stream'])->middleware(['auth', 'permission:admin.manage|platform.manage', 'throttle:60,1']);
Route::get('/api/public/tts/voices', [TtsController::class, 'voices']);
Route::get('/api/public/tts/token/{token}', [TtsController::class, 'token']);
Route::get('/api/public/tts/station/{station}/{lang}', [TtsController::class, 'stationDirections'])
    ->whereIn('lang', ['en', 'fil', 'ilo']);

// Per 05-SECURITY-CONTROLS §3.4: admin UI shared by admin and super_admin (dashboard, users, logs, settings, sites).
Route::middleware(['auth', 'permission:admin.shared'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::redirect('/', '/admin/dashboard', 302)->name('index');
    Route::get('/dashboard', [DashboardPageController::class, 'index'])->name('dashboard');
    Route::get('/users', [UserPageController::class, 'index'])->name('users');
    Route::get('/logs', [ReportPageController::class, 'index'])->name('logs');
    Route::get('/settings', fn () => Inertia::render('Admin/Settings/Index'))->name('settings');
    Route::get('/sites', [SitesPageController::class, 'index'])->name('sites');
    Route::get('/sites/create', [SitesPageController::class, 'create'])->name('sites.create');
    Route::get('/sites/{site}', [SitesPageController::class, 'show'])->name('sites.show');
});

// E10: Edge Sync status page — requires auth, stays at /edge/* (not /admin/*) for consistency with other edge lifecycle pages.
Route::middleware(['auth', 'permission:admin.shared'])->group(function (): void {
    Route::get('/edge/sync', [EdgeSyncController::class, 'show'])->name('edge.sync');
});

// Per SUPER-ADMIN-VS-ADMIN-SPEC: programs, tokens, analytics are admin-only (super_admin has no access).
Route::middleware(['auth', 'permission:admin.manage'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/programs', [ProgramPageController::class, 'index'])->name('programs');
    Route::get('/program-default-settings', fn () => Inertia::render('Admin/ProgramDefaultSettings'))->name('program-default-settings');
    Route::get('/programs/{program}', [ProgramPageController::class, 'show'])->name('programs.show');
    Route::get('/tokens', fn () => Inertia::render('Admin/Tokens/Index'))->name('tokens');
    Route::get('/tokens/print', TokenPrintController::class)->name('tokens.print');
    Route::get('/analytics', fn () => Inertia::render('Admin/Analytics/Index'))->name('analytics');
});

// Admin + program supervisors: client list/detail (no reveal; reveal remains API admin-only).
Route::middleware(['auth', 'permission:admin.manage|staff.operations'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/clients', [ClientPageController::class, 'index'])->name('clients');
    Route::get('/clients/{client}', [ClientPageController::class, 'show'])->name('clients.show');
});

// All staff (admin, supervisor, staff): station, client registration (staff), track-overrides, profile, dashboard
Route::middleware(['auth', 'permission:staff.operations'])->group(function (): void {
    Route::get('/dashboard', StaffDashboardController::class)->name('dashboard');
    Route::get('/station/{station?}', StationPageController::class)->name('station');
    Route::get('/client-registration', TriagePageController::class)->name('client-registration');
    Route::get('/triage', function (Request $request) {
        $qs = $request->getQueryString();

        return redirect('/client-registration'.($qs !== '' && $qs !== null ? '?'.$qs : ''), 302);
    })->name('triage');
    Route::get('/devices', [DisplayController::class, 'devicesForStaff'])->name('devices');
    Route::redirect('/authorize', '/track-overrides', 302)->name('authorize');

    // Canonical: Track overrides (pending permission requests, scan QR to approve)
    Route::get('/track-overrides', ProgramOverridesPageController::class)->name('track-overrides');

    // Backwards compatibility: old URL redirects to canonical
    Route::redirect('/program-overrides', '/track-overrides', 302);
    Route::get('/profile', function (Request $request) {
        $user = $request->user();
        if ($user === null) {
            abort(403);
        }

        return Inertia::render('Profile/Index', [
            'googleOAuthEnabled' => GoogleOAuthConfig::isConfigured(),
            'googleLinked' => $user->credentials()->where('provider', UserCredential::PROVIDER_GOOGLE)->exists(),
            'recoveryGmail' => $user->recovery_gmail,
        ]);
    })->name('profile');
});

// All other web routes require authentication
Route::middleware(['auth', 'permission:profile.self'])->group(function (): void {
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
