<?php

namespace App\Http\Controllers;

use App\Models\IdentityRegistration;
use App\Models\Program;
use App\Models\Site;
use App\Services\EdgeModeService;
use App\Services\MobileCryptoService;
use App\Services\StaffProgramAccessService;
use App\Services\StationQueueService;
use App\Support\PermissionCatalog;
use App\Support\SiteResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per 09-UI-ROUTES-PHASE1 §3.2: Triage page with activeProgram and tracks for category/track select.
 * Per central-edge A.2.2: program resolved from user.assignedStation.program; 422 when no station.
 * Per follow-up: admin/supervisor with no station use same session program as Station (one selection for both).
 */
class TriagePageController extends Controller
{
    public function __construct(
        private StationQueueService $stationQueueService,
        private StaffProgramAccessService $staffProgramAccessService,
    ) {}

    public function __invoke(Request $request): Response|RedirectResponse
    {
        if (! config('flexiqueue.staff_triage_page_enabled', true)) {
            $qs = $request->getQueryString();
            $target = '/station'.($qs !== '' && $qs !== null ? '?'.$qs : '');

            return redirect()->to($target)->with('success', 'Use the footer QR button for client registration and token status.');
        }

        $user = $request->user();
        $user->load(['assignedStation.program.serviceTracks']);

        $isAdminOrSupervisorWithoutStation = $this->staffProgramAccessService->mayUseProgramPickerWithoutAssignedStation($user)
            && $user->assignedStation === null;

        // Staff with per-program station assignments in more than one active program can choose
        // which program to work in; shares the same session key as Station page.
        $staffHasMultiProgramAssignments = ! $user->can(PermissionCatalog::ADMIN_MANAGE)
            && ! $user->can(PermissionCatalog::PLATFORM_MANAGE)
            && ! $user->isSupervisorForAnyProgram()
            && $user->programStationAssignments()
                ->whereHas('program', fn ($q) => $q->where('is_active', true))
                ->distinct('program_id')
                ->count('program_id') > 1;

        $canSwitchProgram = $isAdminOrSupervisorWithoutStation || $staffHasMultiProgramAssignments;

        // Resolve site for program list and ?program= validation: staff use their site so multi-program selector works when not on default site.
        $siteId = $user->site_id ?? SiteResolver::default()->id;

        // Optional ?program=id: set session and redirect (shared with Station so one selection applies to both).
        if ($canSwitchProgram && $request->has('program')) {
            $programId = (int) $request->query('program');
            $programModel = Program::query()
                ->forSite($siteId)
                ->where('id', $programId)
                ->where('is_active', true)
                ->first();
            if ($programModel) {
                $request->session()->put(StationPageController::SESSION_KEY_PROGRAM_ID, $programModel->id);

                return redirect()->route('client-registration');
            }
        }

        // Shared resolver with Station/Overrides:
        // - Session key → assigned station program → first active program.
        $program = StationPageController::resolveProgramForStaffWithoutStation($request);

        if (! $program) {
            $response = redirect()->back()->withErrors(['station' => 'No station assigned.']);
            $response->setStatusCode(422);

            return $response;
        }

        $program->load('serviceTracks:id,program_id,name,color_code,is_default');
        $programSettings = $program->settings();

        $site = Site::find($siteId);

        $programPayload = [
            'id' => $program->id,
            'name' => $program->name,
            'is_active' => $program->is_active,
            'is_paused' => $program->is_paused,
            // Per XM2O identity-binding plan: expose mode to staff triage UI.
            // Per final-edge-mode-rush-plann [DF-13]: use effective mode (required→optional when edge offline).
            'identity_binding_mode' => app(EdgeModeService::class)
                ->getEffectiveBindingMode($programSettings->getIdentityBindingMode()),
            'allow_unverified_entry' => $programSettings->getAllowUnverifiedEntry(),
            'tracks' => $program->serviceTracks->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'color_code' => $t->color_code,
                'is_default' => $t->is_default,
            ])->values()->all(),
        ];

        $footerStats = $this->stationQueueService->getProgramFooterStats($program);

        $mobileCrypto = app(MobileCryptoService::class);
        $pendingRegistrations = IdentityRegistration::query()
            ->forProgram($program->id)
            ->pending()
            ->with(['session.token', 'idVerifiedBy', 'token', 'track', 'client'])
            ->orderByDesc('requested_at')
            ->get()
            ->map(function ($r) use ($mobileCrypto) {
                $mobileMasked = $r->mobile_encrypted
                    ? $mobileCrypto->mask($mobileCrypto->decrypt($r->mobile_encrypted))
                    : null;

                $item = [
                    'id' => $r->id,
                    'request_type' => $r->request_type ?? 'registration',
                    'first_name' => $r->first_name,
                    'middle_name' => $r->middle_name,
                    'last_name' => $r->last_name,
                    'birth_date' => $r->birth_date?->format('Y-m-d'),
                    'address_line_1' => $r->address_line_1,
                    'address_line_2' => $r->address_line_2,
                    'city' => $r->city,
                    'state' => $r->state,
                    'postal_code' => $r->postal_code,
                    'country' => $r->country,
                    'client_category' => $r->client_category,
                    'mobile_masked' => $mobileMasked,
                    'id_verified' => (bool) $r->id_verified,
                    'id_verified_at' => $r->id_verified_at?->toIso8601String(),
                    'id_verified_by_user_id' => $r->id_verified_by_user_id,
                    'id_verified_by' => $r->idVerifiedBy?->name,
                    'requested_at' => $r->requested_at?->toIso8601String(),
                    'session_id' => $r->session_id,
                    'session_alias' => $r->session?->token?->physical_id ?? null,
                ];
                if ($r->request_type === 'bind_confirmation') {
                    $item['token_id'] = $r->token_id;
                    $item['token_physical_id'] = $r->token?->physical_id;
                    $item['track_id'] = $r->track_id;
                    $item['track_name'] = $r->track?->name;
                    $item['client_id'] = $r->client_id;
                    $item['client_name'] = $r->client?->display_name ?? null;
                }

                return $item;
            })
            ->values()
            ->all();

        $programsForSelector = [];
        if ($canSwitchProgram) {
            $query = Program::query()
                ->forSite($siteId)
                ->where('is_active', true)
                ->orderBy('name');

            if ($staffHasMultiProgramAssignments) {
                $assignedProgramIds = $user->programStationAssignments()
                    ->whereHas('program', fn ($q) => $q->where('is_active', true))
                    ->pluck('program_id')
                    ->unique()
                    ->all();

                $query->whereIn('id', $assignedProgramIds);
            }

            $programsForSelector = $query
                ->get(['id', 'name'])
                ->map(fn (Program $p) => ['id' => $p->id, 'name' => $p->name])
                ->values()
                ->all();
        }

        return Inertia::render('Triage/Index', [
            'currentProgram' => $programPayload,
            'program' => $programPayload,
            'activeProgram' => $programPayload,
            'canSwitchProgram' => $canSwitchProgram,
            'programs' => $programsForSelector,
            'queueCount' => $footerStats['queue_count'],
            'processedToday' => $footerStats['processed_today'],
            'pending_identity_registrations' => $pendingRegistrations,
            'site_slug' => $site?->slug,
            'program_slug' => $program->slug,
        ]);
    }
}
