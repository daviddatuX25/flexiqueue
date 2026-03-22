<?php

namespace App\Http\Controllers;

use App\Models\Program;
use App\Models\Site;
use App\Models\Station;
use App\Services\CheckStatusService;
use App\Services\DeviceAuthorizationService;
use App\Services\DisplayBoardService;
use App\Services\StationQueueService;
use App\Services\StatusFlowDiagramPresenter;
use App\Support\DeviceLock;
use App\Support\SiteResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Per 09-UI-ROUTES: Client-facing informant display (no auth).
 */
class DisplayController extends Controller
{
    public function __construct(
        private DisplayBoardService $displayBoardService,
        private CheckStatusService $checkStatusService,
        private DeviceAuthorizationService $deviceAuth,
        private StationQueueService $stationQueueService,
        private StatusFlowDiagramPresenter $statusFlowDiagramPresenter
    ) {}

    /**
     * Per plan: legacy /display and /public-triage with no slug show only this message. No site list, no picker.
     * When a default site exists, redirect to site display.
     */
    public function showScanQrMessage(): RedirectResponse|Response
    {
        $site = SiteResolver::defaultIfExists();

        if ($site !== null) {
            return redirect()->route('display.site', ['site' => $site->slug]);
        }

        return Inertia::render('Display/ScanQrMessage');
    }

    /**
     * Show the "Now Serving" board for a site (per-site route /site/{site_slug}/display). Public.
     * A.2.4: Resolve program from query param ?program={id}. If absent, show program selector.
     */
    /**
     * Display board: all active programs for the site (no publish filter). Access requires device auth (PIN/QR).
     *
     * @return Response|RedirectResponse
     */
    public function boardWithSite(Request $request, Site $site)
    {
        $programId = $request->query('program');
        $programId = is_numeric($programId) ? (int) $programId : null;

        $programs = Program::query()
            ->forSite($site->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Program $p) => ['id' => $p->id, 'name' => $p->name])
            ->values()
            ->all();

        if ($programId !== null) {
            $program = Program::query()
                ->forSite($site->id)
                ->where('id', $programId)
                ->where('is_active', true)
                ->first();

            if ($program) {
                $devicesUrl = '/site/'.$site->slug.'/program/'.$program->slug;
                $lockToken = $request->query('lock_token');
                $cookieFromToken = null;
                if (is_string($lockToken) && $lockToken !== '') {
                    $lock = DeviceLock::consumeLockToken($lockToken);
                    if ($lock !== null && $lock['site_slug'] === $site->slug && $lock['program_slug'] === $program->slug && $lock['device_type'] === DeviceLock::TYPE_DISPLAY) {
                        $cookieFromToken = DeviceLock::encode($lock['site_slug'], $lock['program_slug'], $lock['device_type'], $lock['station_id'] ?? null);
                        DeviceLock::storeInSession($request, $lock);
                    }
                }
                $authRedirect = $this->requireDeviceAuthOrRedirect($request, $program, $devicesUrl);
                if ($authRedirect !== null) {
                    return $authRedirect;
                }
                if ($cookieFromToken === null && ! DeviceLock::matches($request, $site->slug, $program->slug, DeviceLock::TYPE_DISPLAY)) {
                    return redirect()->to($devicesUrl);
                }
                $data = $this->displayBoardService->getBoardData($program->id, $site->id);
                $data['programs'] = $programs;
                $data['currentProgram'] = ['id' => $program->id, 'name' => $program->name, 'slug' => $program->slug];
                $data['program_not_found'] = false;
                $data['site_slug'] = $site->slug;
                $data['program_slug'] = $program->slug;

                $response = Inertia::render('Display/Board', $data)->toResponse($request);
                if ($cookieFromToken !== null) {
                    $response->headers->setCookie($cookieFromToken);
                }

                return $response;
            }

            $data = $this->displayBoardService->getBoardData(null, $site->id);
            $data['programs'] = $programs;
            $data['currentProgram'] = null;
            $data['program_not_found'] = true;
            $data['site_slug'] = $site->slug;

            return Inertia::render('Display/Board', $data);
        }

        $data = $this->displayBoardService->getBoardData(null, $site->id);
        $data['programs'] = $programs;
        $data['currentProgram'] = null;
        $data['program_not_found'] = false;
        $data['site_slug'] = $site->slug;

        return Inertia::render('Display/Board', $data);
    }

    /**
     * Show station-specific display (calling, queue, activity for one station). Public.
     * Per plan: 404 when station inactive or not in active program.
     */
    public function stationBoard(Station $station): Response
    {
        $site = SiteResolver::defaultIfExists();
        if ($site === null) {
            abort(503, 'Service temporarily unavailable.');
        }

        return $this->stationBoardForSite($site, $station);
    }

    /**
     * Per-site route: station board for a site. Station must belong to a program in this site.
     *
     * @return Response|RedirectResponse
     */
    public function stationBoardWithSite(Site $site, Station $station)
    {
        return $this->stationBoardForSite($site, $station);
    }

    /**
     * @return Response|RedirectResponse
     */
    private function stationBoardForSite(Site $site, Station $station)
    {
        $program = $station->program;
        if (! $program || ! $program->is_active || ! $station->is_active) {
            abort(404);
        }
        if ($program->site_id !== $site->id) {
            abort(404);
        }

        $devicesUrl = '/site/'.$site->slug.'/program/'.$program->slug;
        $request = request();
        $lockToken = $request->query('lock_token');
        $cookieFromToken = null;
        if (is_string($lockToken) && $lockToken !== '') {
            $lock = DeviceLock::consumeLockToken($lockToken);
            $stationId = (int) $station->id;
            if ($lock !== null && $lock['site_slug'] === $site->slug && $lock['program_slug'] === $program->slug && $lock['device_type'] === DeviceLock::TYPE_STATION && isset($lock['station_id']) && $lock['station_id'] === $stationId) {
                $cookieFromToken = DeviceLock::encode($lock['site_slug'], $lock['program_slug'], $lock['device_type'], $stationId);
                DeviceLock::storeInSession($request, $lock);
            }
        }
        $authRedirect = $this->requireDeviceAuthOrRedirect($request, $program, $devicesUrl);
        if ($authRedirect !== null) {
            return $authRedirect;
        }
        if ($cookieFromToken === null && ! DeviceLock::matches($request, $site->slug, $program->slug, DeviceLock::TYPE_STATION, (int) $station->id)) {
            return redirect()->to($devicesUrl);
        }

        $data = $this->displayBoardService->getStationBoardData($station);
        $data['site_slug'] = $site->slug;
        $data['program_id'] = $program->id;
        $data['program_slug'] = $program->slug;

        $response = Inertia::render('Display/StationBoard', $data)->toResponse($request);
        if ($cookieFromToken !== null) {
            $response->headers->setCookie($cookieFromToken);
        }

        return $response;
    }

    /**
     * Per plan: site landing at GET /site/{site_slug}. Lists published programs (including private);
     * private programs require program key when user clicks through (enforced by RequireProgramAccess).
     */
    public function siteLanding(Site $site): Response
    {
        $programs = Program::query()
            ->forSite($site->id)
            ->where('is_active', true)
            ->published()
            ->orderBy('name')
            ->get()
            ->map(fn (Program $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'is_published' => $p->is_published,
                'is_private' => $p->settings()->isPrivate(),
            ])
            ->values()
            ->all();

        $settings = $site->settings ?? [];
        $heroPath = $settings['landing_hero_image_path'] ?? null;
        $heroUrl = $heroPath && is_string($heroPath)
            ? Storage::url($heroPath)
            : null;

        $landing = [
            'hero_title' => $settings['landing_hero_title'] ?? $site->name,
            'hero_description' => $settings['landing_hero_description'] ?? null,
            'hero_image_url' => $heroUrl,
            'sections' => $settings['landing_sections'] ?? [],
            'show_stats' => (bool) ($settings['landing_show_stats'] ?? false),
        ];

        return Inertia::render('Site/Landing', [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'slug' => $site->slug,
            ],
            'programs' => $programs,
            'landing' => $landing,
        ]);
    }

    /**
     * Per public-site plan: read-only board at GET /site/{site}/program/{program}/view.
     * No device auth or lock. RequireSiteAccess ensures site is in known_sites cookie.
     */
    public function publicDisplay(Request $request, Site $site, string $program_slug): Response|RedirectResponse
    {
        $program = Program::query()
            ->where('slug', $program_slug)
            ->where('site_id', $site->id)
            ->where('is_active', true)
            ->first();

        if (! $program) {
            abort(404);
        }

        $data = $this->displayBoardService->getBoardData($program->id, $site->id);
        $programs = Program::query()
            ->forSite($site->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(fn (Program $p) => ['id' => $p->id, 'name' => $p->name, 'slug' => $p->slug])
            ->values()
            ->all();

        $data['programs'] = $programs;
        $data['currentProgram'] = ['id' => $program->id, 'name' => $program->name, 'slug' => $program->slug];
        $data['site_slug'] = $site->slug;
        $data['program_slug'] = $program->slug;
        $data['publicView'] = true;

        return Inertia::render('Display/Board', $data);
    }

    /**
     * Per addition-to-public-site-plan Part 7: public program info page at GET /site/{site}/program/{program}/info.
     */
    public function programInfo(Request $request, Site $site, string $program_slug): Response
    {
        $program = Program::query()
            ->where('slug', $program_slug)
            ->where('site_id', $site->id)
            ->where('is_active', true)
            ->first();

        if (! $program) {
            abort(404);
        }

        $settings = $program->settings ?? [];
        $bannerPath = $settings['page_banner_image_path'] ?? null;
        $bannerUrl = $bannerPath && is_string($bannerPath)
            ? Storage::url($bannerPath)
            : null;

        return Inertia::render('Site/ProgramInfo', [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'slug' => $site->slug,
            ],
            'program' => [
                'id' => $program->id,
                'name' => $program->name,
                'slug' => $program->slug,
                'description' => $program->description,
                'is_active' => $program->is_active,
                'is_paused' => $program->is_paused ?? false,
            ],
            'page' => [
                'description' => $program->settings()->getPageDescription(),
                'announcement' => $program->settings()->getPageAnnouncement(),
                'banner_image_url' => $bannerUrl,
            ],
            'is_private' => $program->settings()->isPrivate(),
        ]);
    }

    /**
     * Per plan: program page at GET /site/{site_slug}/program/{program_slug}. When active, shows device selection.
     * If inactive or not found, 404.
     */
    public function programPage(Site $site, string $program_slug): Response|RedirectResponse
    {
        return $this->renderProgramDeviceChooser($site, $program_slug);
    }

    /**
     * Staff-only: show device chooser for current program without PIN/QR. Resolves site and program like Station page.
     */
    public function devicesForStaff(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $siteId = $user->site_id ?? SiteResolver::default()->id;
        $site = Site::find($siteId);

        if (! $site) {
            return redirect()->route('station')->with('flash', [
                'error' => 'No site is configured for your account.',
            ]);
        }

        $program = StationPageController::resolveProgramForStaffWithoutStation($request);

        if (! $program) {
            return redirect()->route('station')->with('flash', [
                'error' => 'No active program is available for this site.',
            ]);
        }

        $isAdminOrSupervisorWithoutStation = ($user->isAdmin() || $user->isSupervisorForAnyProgram()) && $user->assignedStation === null;

        $staffHasMultiProgramAssignments = ! $user->isAdmin()
            && ! $user->isSupervisorForAnyProgram()
            && $user->programStationAssignments()
                ->whereHas('program', fn ($q) => $q->where('is_active', true))
                ->distinct('program_id')
                ->count('program_id') > 1;

        $canSwitchProgram = $isAdminOrSupervisorWithoutStation || $staffHasMultiProgramAssignments;

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

        $stations = $program->stations()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Station $s) => ['id' => $s->id, 'name' => $s->name])
            ->values()
            ->all();

        $footerStats = $this->stationQueueService->getProgramFooterStats($program);

        $currentProgramPayload = [
            'id' => $program->id,
            'name' => $program->name,
            'is_active' => $program->is_active,
            'is_paused' => $program->is_paused ?? false,
        ];

        return Inertia::render('Display/DeviceTypeChoose', [
            'site_slug' => $site->slug,
            'program' => [
                'id' => $program->id,
                'name' => $program->name,
                'slug' => $program->slug,
            ],
            'stations' => $stations,
            'queueCount' => $footerStats['queue_count'],
            'processedToday' => $footerStats['processed_today'],
            'activeProgram' => $currentProgramPayload,
            'currentProgram' => $currentProgramPayload,
            'canSwitchProgram' => $canSwitchProgram,
            'programs' => $programsForSelector,
        ]);
    }

    /**
     * Per plan: legacy .../devices URL redirects to canonical program URL so one place for device selection.
     */
    public function chooseDeviceType(Site $site, string $program_slug): RedirectResponse
    {
        $program = Program::query()
            ->where('slug', $program_slug)
            ->where('site_id', $site->id)
            ->where('is_active', true)
            ->first();

        if (! $program) {
            abort(404);
        }

        return redirect()->to('/site/'.$site->slug.'/program/'.$program->slug, 302);
    }

    /**
     * Shared logic: resolve active program for site+slug, device auth, then render device chooser (or redirect to auth).
     * If existing lock is for a different program, clear it before rendering.
     */
    private function renderProgramDeviceChooser(Site $site, string $program_slug): Response|RedirectResponse
    {
        $program = Program::query()
            ->where('slug', $program_slug)
            ->where('site_id', $site->id)
            ->where('is_active', true)
            ->first();

        if (! $program) {
            abort(404);
        }

        $request = request();
        $lock = DeviceLock::decode($request);
        $clearLockCookie = $lock !== null && $lock['program_slug'] !== $program->slug;

        $devicesUrl = '/site/'.$site->slug.'/program/'.$program->slug;
        $authRedirect = $this->requireDeviceAuthOrRedirect($request, $program, $devicesUrl);
        if ($authRedirect !== null) {
            if ($clearLockCookie) {
                DeviceLock::clearFromSession($request);
            }

            return $clearLockCookie
                ? $authRedirect->withCookie(DeviceLock::clearCookie())
                : $authRedirect;
        }

        $stations = $program->stations()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Station $s) => ['id' => $s->id, 'name' => $s->name])
            ->values()
            ->all();

        $payload = [
            'site_slug' => $site->slug,
            'program' => [
                'id' => $program->id,
                'name' => $program->name,
                'slug' => $program->slug,
            ],
            'stations' => $stations,
        ];
        if ($request->user()) {
            $footerStats = $this->stationQueueService->getProgramFooterStats($program);
            $payload['queueCount'] = $footerStats['queue_count'];
            $payload['processedToday'] = $footerStats['processed_today'];
        }

        $page = Inertia::render('Display/DeviceTypeChoose', $payload);

        if ($clearLockCookie) {
            DeviceLock::clearFromSession($request);
        }

        return $clearLockCookie ? $page->withCookie(DeviceLock::clearCookie()) : $page;
    }

    /**
     * Per plan: legacy /public-triage with no slug. When default site exists, redirect to site triage start;
     * otherwise show "Please scan the QR..." (ScanQrMessage).
     */
    public function triageStartRedirect(): RedirectResponse|Response
    {
        $site = SiteResolver::defaultIfExists();

        if ($site !== null) {
            return redirect()->route('public.kiosk.site', ['site' => $site->slug], 301);
        }

        return Inertia::render('Display/ScanQrMessage');
    }

    /**
     * Per-site kiosk start: redirect to canonical `/kiosk` list handler (replaces legacy public-triage index).
     */
    public function triageStartWithSite(Site $site): RedirectResponse
    {
        return redirect()->route('public.kiosk.site', ['site' => $site->slug], 301);
    }

    /**
     * First active program with kiosk self-service enabled, or empty PublicStart. Canonical: `/site/{site}/kiosk`.
     */
    public function kioskStartWithSite(Site $site): RedirectResponse|Response
    {
        $program = Program::query()
            ->forSite($site->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->first(fn (Program $p) => $p->settings()->getKioskSurfaceEnabled());

        if ($program) {
            return redirect()->to('/site/'.$site->slug.'/kiosk/'.$program->slug);
        }

        return Inertia::render('Triage/PublicStart', [
            'allowed' => false,
            'program_id' => null,
            'site_id' => null,
            'program_name' => null,
            'tracks' => [],
            'date' => now()->format('F j, Y'),
            'display_scan_timeout_seconds' => 20,
            'enable_public_triage_hid_barcode' => true,
            'enable_public_triage_camera_scanner' => true,
            'allow_unverified_entry' => false,
        ]);
    }

    /**
     * Per-site self-service kiosk by program slug (canonical URL). Legacy `/public-triage/{program}` redirects here (301).
     */
    public function publicKioskWithSite(Site $site, string $program_slug): SymfonyResponse
    {
        $program = Program::query()
            ->where('slug', $program_slug)
            ->where('site_id', $site->id)
            ->first();

        if (! $program) {
            abort(404);
        }

        return $this->publicSelfServeKioskForProgram($program);
    }

    /**
     * Legacy route: permanent redirect to kiosk URL.
     */
    public function publicTriageWithSite(Site $site, string $program_slug): RedirectResponse
    {
        return redirect()->route('public.kiosk.site.program', [
            'site' => $site->slug,
            'program_slug' => $program_slug,
        ], 301);
    }

    /**
     * Legacy route `/public/triage/{program}`: permanent redirect to site kiosk URL.
     */
    public function publicTriage(Program $program): RedirectResponse
    {
        if (! $program->is_active) {
            abort(404);
        }
        $site = $program->site;
        if (! $site) {
            abort(404);
        }

        return redirect()->to('/site/'.$site->slug.'/kiosk/'.$program->slug, 301);
    }

    /**
     * Self-service kiosk surface (same Inertia page as legacy public triage; device lock type kiosk or legacy triage).
     */
    private function publicSelfServeKioskForProgram(Program $program): SymfonyResponse
    {
        $site = $program->site;
        if (! $site) {
            abort(404);
        }
        $program->load('serviceTracks:id,program_id,name,is_default');
        $settings = $program->settings();

        $request = request();

        if (! $settings->getKioskSurfaceEnabled()) {
            return Inertia::render('Triage/PublicStart', [
                'allowed' => false,
                'program_id' => $program->id,
                'site_id' => $program->site_id,
                'site_slug' => $site->slug,
                'program_slug' => $program->slug,
                'program_name' => $program->name,
                'tracks' => [],
                'date' => now()->format('F j, Y'),
                'display_scan_timeout_seconds' => $settings->getKioskModalIdleSeconds(),
                'enable_public_triage_hid_barcode' => $settings->getKioskEnableHidBarcode(),
                'enable_public_triage_camera_scanner' => $settings->getKioskEnableCameraScanner(),
                'allow_unverified_entry' => $settings->getAllowUnverifiedEntry(),
                'kiosk_self_service_triage_enabled' => $settings->getKioskSelfServiceTriageEnabled(),
                'kiosk_status_checker_enabled' => $settings->getKioskStatusCheckerEnabled(),
                'kiosk_hid_persistent_when_scan_modal_closed' => $settings->getKioskHidPersistentWhenScanModalClosed(),
            ])->toResponse($request);
        }

        $devicesUrl = '/site/'.$site->slug.'/program/'.$program->slug;
        $lockToken = $request->query('lock_token');
        $cookieFromToken = null;
        if (is_string($lockToken) && $lockToken !== '') {
            $lock = DeviceLock::consumeLockToken($lockToken);
            if ($lock !== null && $lock['site_slug'] === $site->slug && $lock['program_slug'] === $program->slug
                && in_array($lock['device_type'], [DeviceLock::TYPE_KIOSK, DeviceLock::TYPE_TRIAGE], true)) {
                $cookieFromToken = DeviceLock::encode($lock['site_slug'], $lock['program_slug'], $lock['device_type'], $lock['station_id'] ?? null);
                DeviceLock::storeInSession($request, $lock);
            }
        }
        $authRedirect = $this->requireDeviceAuthOrRedirect($request, $program, $devicesUrl);
        if ($authRedirect !== null) {
            return $authRedirect;
        }
        $hasKioskLock = DeviceLock::matches($request, $site->slug, $program->slug, DeviceLock::TYPE_KIOSK)
            || DeviceLock::matches($request, $site->slug, $program->slug, DeviceLock::TYPE_TRIAGE);
        if ($cookieFromToken === null && ! $hasKioskLock) {
            return redirect()->to($devicesUrl);
        }

        $tracks = $program->serviceTracks->map(fn ($t) => [
            'id' => $t->id,
            'name' => $t->name,
            'is_default' => (bool) $t->is_default,
        ])->values()->all();

        $response = Inertia::render('Triage/PublicStart', [
            'allowed' => true,
            'program_id' => $program->id,
            'site_id' => $program->site_id,
            'site_slug' => $site->slug,
            'program_slug' => $program->slug,
            'program_name' => $program->name,
            'tracks' => $tracks,
            'identity_binding_mode' => $settings->getIdentityBindingMode(),
            'date' => now()->format('F j, Y'),
            'display_scan_timeout_seconds' => $settings->getKioskModalIdleSeconds(),
            'enable_public_triage_hid_barcode' => $settings->getKioskEnableHidBarcode(),
            'enable_public_triage_camera_scanner' => $settings->getKioskEnableCameraScanner(),
            'allow_unverified_entry' => $settings->getAllowUnverifiedEntry(),
            'kiosk_self_service_triage_enabled' => $settings->getKioskSelfServiceTriageEnabled(),
            'kiosk_status_checker_enabled' => $settings->getKioskStatusCheckerEnabled(),
            'kiosk_hid_persistent_when_scan_modal_closed' => $settings->getKioskHidPersistentWhenScanModalClosed(),
        ])->toResponse($request);
        if ($cookieFromToken !== null) {
            $response->headers->setCookie($cookieFromToken);
        }

        return $response;
    }

    /**
     * Show client status after QR scan (site-scoped URL). Public. Per 08-API-SPEC §2.1.
     * Token must belong to the given site_id so cross-site tokens are not recognized.
     */
    public function statusWithSite(int $site_id, string $qr_hash): Response
    {
        $data = $this->checkStatusService->getStatus($qr_hash, $site_id);

        return $this->renderStatusResponse($data, $site_id);
    }

    /**
     * Per-site status: /site/{site_slug}/display/status/{qr_hash}.
     */
    public function statusWithSiteSlug(Site $site, string $qr_hash): Response
    {
        $data = $this->checkStatusService->getStatus($qr_hash, $site->id);

        return $this->renderStatusResponse($data, $site->id);
    }

    /**
     * Show client status after QR scan (legacy single-segment URL). Public. Per 08-API-SPEC §2.1.
     * Pass display_scan_timeout_seconds from active program so Status page auto-dismiss matches board scanner setting.
     */
    public function status(string $qr_hash): Response
    {
        $site = SiteResolver::defaultIfExists();
        if ($site === null) {
            abort(503, 'Service temporarily unavailable.');
        }
        $data = $this->checkStatusService->getStatus($qr_hash, $site->id);

        return $this->renderStatusResponse($data, $site->id);
    }

    /**
     * Shared response builder for status and statusWithSite.
     *
     * @param  array{result: string, program_id?: int, track_id?: int, ...}  $data
     * @param  int|null  $siteId  When set, resolve program with forSite so response is site-scoped.
     */
    private function renderStatusResponse(array $data, ?int $siteId = null): Response
    {

        $inertiaProps = $this->checkStatusResultToInertiaProps($data);

        // Per central-edge Phase A: program from token/session when in_use, else optional ?program=; no single-active. Site-scoped when $siteId set.
        $programId = (int) ($data['program_id'] ?? request()->query('program') ?? 0);
        $program = null;
        if ($programId > 0) {
            $q = Program::query()->where('id', $programId);
            if ($siteId !== null) {
                $q->forSite($siteId);
            }
            $program = $q->first();
        }
        $inertiaProps['display_scan_timeout_seconds'] = $program ? $program->settings()->getDisplayScanTimeoutSeconds() : 20;
        $inertiaProps['program_name'] = $program?->name;
        $inertiaProps['date'] = now()->format('F j, Y');
        $inertiaProps['enable_display_hid_barcode'] = $program ? $program->settings()->getEnableDisplayHidBarcode() : true;
        $inertiaProps['enable_display_camera_scanner'] = $program ? $program->settings()->getEnableDisplayCameraScanner() : true;

        if ($data['result'] === 'in_use' && ! empty($data['program_id']) && ! empty($data['track_id'])) {
            $this->addDiagramProps($inertiaProps, (int) $data['program_id'], (int) $data['track_id'], $siteId);
        }

        $inertiaProps['site_slug'] = $siteId !== null ? (Site::find($siteId))?->slug : null;
        $inertiaProps['return_url'] = $this->sanitizeStatusReturnTo(request()->query('return_to'));

        return Inertia::render('Display/Status', $inertiaProps);
    }

    /**
     * Full-page status: optional safe redirect after timer / “Go back” (e.g. kiosk ?return_to=/site/.../public-triage/...).
     * Only same-origin path prefixes under /site/ are allowed.
     */
    private function sanitizeStatusReturnTo(mixed $raw): ?string
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $path = rawurldecode($raw);
        if (! str_starts_with($path, '/')) {
            return null;
        }
        if (str_contains($path, "\0") || preg_match('#//|\\\\#', $path)) {
            return null;
        }
        if (! str_starts_with($path, '/site/')) {
            return null;
        }

        return $path;
    }

    /**
     * Map CheckStatusService result to Display/Status page props.
     *
     * @param  array{result: string, error?: string, alias?: string, ...}  $data
     * @return array{error: string|null, alias: string|null, status: string|null, client_category?: string, progress: array|null, ...}
     */
    private function checkStatusResultToInertiaProps(array $data): array
    {
        $base = [
            'error' => $data['error'] ?? null,
            'alias' => $data['alias'] ?? null,
            'status' => $data['status'] ?? null,
            'client_category' => $data['client_category'] ?? null,
            'progress' => $data['progress'] ?? null,
            'current_station' => $data['current_station'] ?? null,
            'estimated_wait_minutes' => $data['estimated_wait_minutes'] ?? null,
            'started_at' => $data['started_at'] ?? null,
            'message' => $data['message'] ?? null,
        ];

        if ($data['result'] === 'not_found') {
            $base['error'] = $data['error'] ?? 'Token not found.';
        }

        return $base;
    }

    /**
     * Add diagram props when program has a saved diagram (for client flow view).
     *
     * @param  array<string, mixed>  $inertiaProps
     * @param  int|null  $siteId  When set, scope program lookup to site.
     */
    private function addDiagramProps(array &$inertiaProps, int $programId, int $trackId, ?int $siteId = null): void
    {
        foreach ($this->statusFlowDiagramPresenter->propsForProgramTrack($programId, $trackId, $siteId) as $key => $value) {
            $inertiaProps[$key] = $value;
        }
    }

    /**
     * Per plan Step 5: Display and triage require device auth (PIN/QR). If not authorized, return DeviceAuthorize page.
     * When $redirectUrl is provided (e.g. choose page), use it so after auth the user lands on choose, not the original URL.
     * Authenticated staff/admin/supervisor skip device auth — they are already logged in.
     */
    private function requireDeviceAuthOrRedirect(Request $request, Program $program, ?string $redirectUrl = null): ?Response
    {
        if ($request->user() !== null) {
            return null;
        }

        $cookieName = DeviceAuthorizationService::cookieNameForProgram($program);
        $cookieValue = $request->cookie($cookieName);

        if ($this->deviceAuth->isAuthorized($program, $cookieValue)) {
            return null;
        }

        $url = $redirectUrl ?? $request->url();

        return $this->renderDeviceAuthorizePage($program, $program->site, $url);
    }

    /**
     * Per plan Step 5: Render the "Authorize this device" page (PIN/QR required).
     */
    private function renderDeviceAuthorizePage(Program $program, Site $site, string $redirectUrl): Response
    {
        return Inertia::render('Display/DeviceAuthorize', [
            'program_id' => $program->id,
            'program_name' => $program->name,
            'program_slug' => $program->slug,
            'site_slug' => $site->slug,
            'redirect_url' => $redirectUrl,
        ]);
    }
}
