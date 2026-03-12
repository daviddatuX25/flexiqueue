<?php

namespace App\Http\Controllers;

use App\Models\IdentityRegistration;
use App\Models\Program;
use App\Support\ClientIdTypes;
use App\Services\StationQueueService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per 09-UI-ROUTES-PHASE1 §3.2: Triage page with activeProgram and tracks for category/track select.
 */
class TriagePageController extends Controller
{
    public function __construct(
        private StationQueueService $stationQueueService
    ) {}

    public function __invoke(Request $request): Response
    {
        $activeProgram = Program::where('is_active', true)->with('serviceTracks:id,program_id,name,color_code,is_default')->first();

        $programPayload = null;
        if ($activeProgram) {
            $programSettings = $activeProgram->settings();

            $programPayload = [
                'id' => $activeProgram->id,
                'name' => $activeProgram->name,
                'is_active' => $activeProgram->is_active,
                'is_paused' => $activeProgram->is_paused,
                // Per XM2O identity-binding plan: expose mode to staff triage UI.
                'identity_binding_mode' => $programSettings->getIdentityBindingMode(),
                'tracks' => $activeProgram->serviceTracks->map(fn ($t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'color_code' => $t->color_code,
                    'is_default' => $t->is_default,
                ])->values()->all(),
            ];
        }

        $footerStats = $this->stationQueueService->getProgramFooterStats($activeProgram);
        $displayScanTimeoutSeconds = $activeProgram ? $activeProgram->settings()->getDisplayScanTimeoutSeconds() : 20;

        $pendingRegistrations = [];
        if ($activeProgram) {
            $pendingRegistrations = IdentityRegistration::query()
                ->forProgram($activeProgram->id)
                ->pending()
                ->with(['session.token', 'idVerifiedBy'])
                ->orderByDesc('requested_at')
                ->get()
                ->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'birth_year' => $r->birth_year,
                    'client_category' => $r->client_category,
                    'id_type' => $r->id_type,
                    'id_number_last4' => $r->id_number_last4,
                    'id_verified_at' => $r->id_verified_at?->toIso8601String(),
                    'id_verified_by_user_id' => $r->id_verified_by_user_id,
                    'id_verified_by' => $r->idVerifiedBy?->name,
                    'requested_at' => $r->requested_at?->toIso8601String(),
                    'session_id' => $r->session_id,
                    'session_alias' => $r->session?->token?->physical_id ?? null,
                ])
                ->values()
                ->all();
        }

        $user = $request->user();

        return Inertia::render('Triage/Index', [
            'activeProgram' => $programPayload,
            'queueCount' => $footerStats['queue_count'],
            'processedToday' => $footerStats['processed_today'],
            'display_scan_timeout_seconds' => $displayScanTimeoutSeconds,
            'staff_triage_allow_hid_barcode' => $user->staff_triage_allow_hid_barcode ?? true,
            'staff_triage_allow_camera_scanner' => $user->staff_triage_allow_camera_scanner ?? true,
            'id_types' => ClientIdTypes::all(),
            'pending_identity_registrations' => $pendingRegistrations,
        ]);
    }
}
