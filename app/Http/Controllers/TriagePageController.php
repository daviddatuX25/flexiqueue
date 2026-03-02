<?php

namespace App\Http\Controllers;

use App\Models\Program;
use App\Services\StationQueueService;
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

    public function __invoke(): Response
    {
        $activeProgram = Program::where('is_active', true)->with('serviceTracks:id,program_id,name,color_code,is_default')->first();

        $programPayload = null;
        if ($activeProgram) {
            $programPayload = [
                'id' => $activeProgram->id,
                'name' => $activeProgram->name,
                'is_active' => $activeProgram->is_active,
                'is_paused' => $activeProgram->is_paused,
                'tracks' => $activeProgram->serviceTracks->map(fn ($t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'color_code' => $t->color_code,
                    'is_default' => $t->is_default,
                ])->values()->all(),
            ];
        }

        $footerStats = $this->stationQueueService->getProgramFooterStats($activeProgram);
        $displayScanTimeoutSeconds = $activeProgram ? $activeProgram->getDisplayScanTimeoutSeconds() : 20;

        return Inertia::render('Triage/Index', [
            'activeProgram' => $programPayload,
            'queueCount' => $footerStats['queue_count'],
            'processedToday' => $footerStats['processed_today'],
            'display_scan_timeout_seconds' => $displayScanTimeoutSeconds,
        ]);
    }
}
