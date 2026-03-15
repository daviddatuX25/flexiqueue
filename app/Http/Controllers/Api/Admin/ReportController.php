<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Per 08-API-SPEC-PHASE1 §5.8: Audit log API (program sessions + audit log). Auth: role:admin.
 */
class ReportController extends Controller
{
    public function __construct(
        private ReportService $reportService
    ) {}

    /**
     * Get program sessions (start+end pairs) for filter dropdown.
     * Query: ?program_id=1&from=Y-m-d&to=Y-m-d
     * Per site-scoping-migration-spec §5: site admin restricted to own site's programs; super_admin can pass any.
     */
    public function programSessions(Request $request): JsonResponse
    {
        $siteId = $this->resolveReportSiteId($request);
        if ($siteId === false) {
            return response()->json(['message' => 'Site admin must have a site.'], 403);
        }

        $filters = [
            'program_id' => $request->query('program_id'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ];

        $sessions = $this->reportService->getProgramSessions($filters, $siteId);

        return response()->json(['program_sessions' => $sessions]);
    }

    /**
     * Get audit log entries. Query: ?program_id=1&from=Y-m-d&to=Y-m-d&action_type=...&page=1
     * Per SUPER-ADMIN-VS-ADMIN-SPEC: ?scope=admin and super_admin returns only admin action log.
     */
    public function audit(Request $request): JsonResponse
    {
        $scope = $request->query('scope');
        if ($scope === 'admin' && $request->user()->isSuperAdmin()) {
            $filters = [
                'from' => $request->query('from'),
                'to' => $request->query('to'),
                'page' => $request->query('page', 1),
                'per_page' => min(100, max(10, (int) $request->query('per_page', 50))),
            ];
            $paginator = $this->reportService->getAdminActionLog($filters);

            return response()->json([
                'data' => $paginator->getCollection()->values()->all(),
                'meta' => [
                    'total' => $paginator->total(),
                    'per_page' => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                ],
                'scope' => 'admin',
            ]);
        }

        $siteId = $this->resolveReportSiteId($request);
        if ($siteId === false) {
            return response()->json(['message' => 'Site admin must have a site.'], 403);
        }

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

        $paginator = $this->reportService->getAuditLog($filters, $siteId);

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
     * Download audit log as CSV. Same query params as audit.
     * Per SUPER-ADMIN-VS-ADMIN-SPEC: ?scope=admin and super_admin returns admin action log CSV only.
     */
    public function auditExport(Request $request): StreamedResponse
    {
        if ($request->query('scope') === 'admin' && $request->user()->isSuperAdmin()) {
            $filters = [
                'from' => $request->query('from'),
                'to' => $request->query('to'),
            ];

            return $this->reportService->streamAdminActionCsv($filters);
        }

        $filters = [
            'program_id' => $request->query('program_id'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'action_type' => $request->query('action_type'),
            'station_id' => $request->query('station_id'),
            'staff_user_id' => $request->query('staff_user_id'),
            'program_session_id' => $request->query('program_session_id'),
        ];

        $siteId = $this->resolveReportSiteId($request);
        if ($siteId === false) {
            return response()->json(['message' => 'Site admin must have a site.'], 403);
        }

        return $this->reportService->streamAuditCsv($filters, $siteId);
    }

    /**
     * Resolve site_id for report/audit scoping. Per site-scoping-migration-spec §5.
     *
     * @return int|null|false null = super_admin (allow any), int = site_id, false = 403 (site admin with no site)
     */
    private function resolveReportSiteId(Request $request): int|null|false
    {
        $user = $request->user();
        if ($user->isSuperAdmin()) {
            return null;
        }
        if ($user->site_id === null) {
            return false;
        }

        return $user->site_id;
    }
}
