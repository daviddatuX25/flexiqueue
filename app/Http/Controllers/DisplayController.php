<?php

namespace App\Http\Controllers;

use App\Models\Program;
use App\Models\ProgramStationAssignment;
use App\Models\ServiceTrack;
use App\Models\Station;
use App\Services\CheckStatusService;
use App\Services\DisplayBoardService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per 09-UI-ROUTES: Client-facing informant display (no auth).
 */
class DisplayController extends Controller
{
    public function __construct(
        private DisplayBoardService $displayBoardService,
        private CheckStatusService $checkStatusService
    ) {}

    /**
     * Show the "Now Serving" board. Public.
     */
    public function board(): Response
    {
        $data = $this->displayBoardService->getBoardData();

        return Inertia::render('Display/Board', $data);
    }

    /**
     * Show station-specific display (calling, queue, activity for one station). Public.
     * Per plan: 404 when station inactive or not in active program.
     */
    public function stationBoard(Station $station): Response
    {
        $program = Program::query()->where('is_active', true)->first();
        if (! $program || $station->program_id !== $program->id || ! $station->is_active) {
            abort(404);
        }

        $data = $this->displayBoardService->getStationBoardData($station);

        return Inertia::render('Display/StationBoard', $data);
    }

    /**
     * Show public self-serve triage page. When program allows, clients can scan token and choose track.
     * When no active program or allow_public_triage is false, show "Self-service is not available".
     */
    public function publicTriage(): Response
    {
        $program = Program::query()->where('is_active', true)->with('serviceTracks:id,program_id,name,is_default')->first();

        if (! $program || ! $program->getAllowPublicTriage()) {
            return Inertia::render('Triage/PublicStart', [
                'allowed' => false,
                'program_name' => null,
                'tracks' => [],
                'date' => now()->format('F j, Y'),
                'display_scan_timeout_seconds' => 20,
                'enable_public_triage_hid_barcode' => true,
            ]);
        }

        $tracks = $program->serviceTracks->map(fn ($t) => [
            'id' => $t->id,
            'name' => $t->name,
            'is_default' => (bool) $t->is_default,
        ])->values()->all();

        return Inertia::render('Triage/PublicStart', [
            'allowed' => true,
            'program_name' => $program->name,
            'tracks' => $tracks,
            'date' => now()->format('F j, Y'),
            'display_scan_timeout_seconds' => $program->getDisplayScanTimeoutSeconds(),
            'enable_public_triage_hid_barcode' => $program->getEnablePublicTriageHidBarcode(),
        ]);
    }

    /**
     * Show client status after QR scan. Public. Per 08-API-SPEC §2.1 (same logic as check-status).
     * Pass display_scan_timeout_seconds from active program so Status page auto-dismiss matches board scanner setting.
     * When in_use and program has a diagram, pass diagram data for client flow view.
     */
    public function status(string $qr_hash): Response
    {
        $data = $this->checkStatusService->getStatus($qr_hash);

        $inertiaProps = $this->checkStatusResultToInertiaProps($data);

        $program = Program::query()->where('is_active', true)->first();
        $inertiaProps['display_scan_timeout_seconds'] = $program ? $program->getDisplayScanTimeoutSeconds() : 20;
        $inertiaProps['program_name'] = $program?->name;
        $inertiaProps['date'] = now()->format('F j, Y');
        $inertiaProps['enable_display_hid_barcode'] = $program ? $program->getEnableDisplayHidBarcode() : true;

        if ($data['result'] === 'in_use' && ! empty($data['program_id']) && ! empty($data['track_id'])) {
            $this->addDiagramProps($inertiaProps, (int) $data['program_id'], (int) $data['track_id']);
        }

        return Inertia::render('Display/Status', $inertiaProps);
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
     */
    private function addDiagramProps(array &$inertiaProps, int $programId, int $trackId): void
    {
        $program = Program::query()->with('diagram')->find($programId);
        if (! $program || ! $program->diagram) {
            return;
        }

        $layout = $program->diagram->layout;
        if (! is_array($layout) || empty($layout['nodes'])) {
            return;
        }

        $tracks = $program->serviceTracks()
            ->with(['trackSteps.process', 'trackSteps.station'])
            ->orderBy('name')
            ->get()
            ->map(fn (ServiceTrack $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'steps' => $t->trackSteps->map(fn ($s) => [
                    'station_id' => $s->station_id,
                    'process_id' => $s->process_id,
                    'step_order' => $s->step_order,
                ])->values()->all(),
            ])->values()->all();

        $processes = $program->processes()
            ->orderBy('name')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
            ])->values()->all();

        $stations = $program->stations()
            ->with('processes')
            ->orderBy('name')
            ->get()
            ->map(fn (Station $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'process_ids' => $s->processes->pluck('id')->values()->all(),
            ])->values()->all();

        $seen = [];
        $staffList = [];
        foreach (ProgramStationAssignment::where('program_id', $program->id)->with('user:id,name')->get() as $a) {
            $uid = $a->user_id;
            if (! in_array($uid, $seen, true)) {
                $seen[] = $uid;
                $staffList[] = ['id' => $a->user->id, 'name' => $a->user->name];
            }
        }
        foreach ($program->supervisedBy()->get() as $u) {
            if (! in_array($u->id, $seen, true)) {
                $seen[] = $u->id;
                $staffList[] = ['id' => $u->id, 'name' => $u->name];
            }
        }
        usort($staffList, fn ($a, $b) => strcmp($a['name'], $b['name']));

        $inertiaProps['diagram'] = $layout;
        $inertiaProps['diagram_program'] = ['id' => $program->id, 'name' => $program->name];
        $inertiaProps['diagram_tracks'] = $tracks;
        $inertiaProps['diagram_stations'] = $stations;
        $inertiaProps['diagram_processes'] = $processes;
        $inertiaProps['diagram_staff'] = $staffList;
        $inertiaProps['diagram_track_id'] = $trackId;
    }
}
