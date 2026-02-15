<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Per 08-API-SPEC-PHASE1 §5.8: Reports API. Auth: role:admin.
 */
class ReportController extends Controller
{
    public function __construct(
        private ReportService $reportService
    ) {}

    /**
     * Get program sessions (start+end pairs) for filter dropdown.
     * Query: ?program_id=1&from=Y-m-d&to=Y-m-d
     */
    public function programSessions(Request $request): JsonResponse
    {
        $filters = [
            'program_id' => $request->query('program_id'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ];

        $sessions = $this->reportService->getProgramSessions($filters);

        return response()->json(['program_sessions' => $sessions]);
    }

    /**
     * Get audit log entries. Query: ?program_id=1&from=Y-m-d&to=Y-m-d&action_type=override&station_id=2&staff_user_id=3&program_session_id=4&page=1
     */
    public function audit(Request $request): JsonResponse
    {
        $filters = [
            'program_id' => $request->query('program_id'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'action_type' => $request->query('action_type'),
            'station_id' => $request->query('station_id'),
            'staff_user_id' => $request->query('staff_user_id'),
            'program_session_id' => $request->query('program_session_id'),
            'page' => $request->query('page', 1),
            'per_page' => min(100, max(10, (int) $request->query('per_page', 50))),
        ];

        $paginator = $this->reportService->getAuditLog($filters);

        return response()->json([
            'data' => $paginator->getCollection()->values()->all(),
            'meta' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
            ],
        ]);
    }

    /**
     * Download audit log as CSV. Same query params as audit (includes staff_user_id, program_session_id).
     */
    public function auditExport(Request $request): StreamedResponse
    {
        $filters = [
            'program_id' => $request->query('program_id'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'action_type' => $request->query('action_type'),
            'station_id' => $request->query('station_id'),
            'staff_user_id' => $request->query('staff_user_id'),
            'program_session_id' => $request->query('program_session_id'),
        ];

        return $this->reportService->streamAuditCsv($filters);
    }
}
