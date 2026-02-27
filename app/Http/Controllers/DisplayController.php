<?php

namespace App\Http\Controllers;

use App\Models\Program;
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
     * Show client status after QR scan. Public. Per 08-API-SPEC §2.1 (same logic as check-status).
     * Pass display_scan_timeout_seconds from active program so Status page auto-dismiss matches board scanner setting.
     */
    public function status(string $qr_hash): Response
    {
        $data = $this->checkStatusService->getStatus($qr_hash);

        $inertiaProps = $this->checkStatusResultToInertiaProps($data);

        $program = Program::query()->where('is_active', true)->first();
        $inertiaProps['display_scan_timeout_seconds'] = $program ? $program->getDisplayScanTimeoutSeconds() : 20;
        $inertiaProps['program_name'] = $program?->name;
        $inertiaProps['date'] = now()->format('F j, Y');

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
}
