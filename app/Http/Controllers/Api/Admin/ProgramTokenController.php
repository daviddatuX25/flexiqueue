<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignTokensRequest;
use App\Http\Requests\BulkAssignTokensRequest;
use App\Models\Program;
use App\Models\Token;
use App\Services\ProgramTokenService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per central-edge C.3: Admin API for assigning/unassigning tokens to programs.
 * Auth: role:admin. Program must belong to user's site (404 if not).
 */
class ProgramTokenController extends Controller
{
    public function __construct(
        private ProgramTokenService $programTokenService
    ) {}

    /**
     * List tokens for this program (assigned + global). Site-scoped via program.
     * GET /api/admin/programs/{program}/tokens
     */
    public function index(Request $request, Program $program): JsonResponse
    {
        $this->ensureProgramInSite($request, $program);

        $perPage = (int) $request->input('per_page', 0);
        $assignedOnly = $request->boolean('assigned_only');
        $result = $this->programTokenService->listTokensForProgram($program, $perPage, $assignedOnly);

        $mapItem = fn (array $item) => $this->tokenResourceWithSource(
            $item['token'],
            $item['source'],
            $item['can_unassign']
        );

        if ($result instanceof LengthAwarePaginator) {
            $tokens = collect($result->items())->map($mapItem)->values()->all();

            return response()->json([
                'tokens' => $tokens,
                'meta' => [
                    'current_page' => $result->currentPage(),
                    'last_page' => $result->lastPage(),
                    'per_page' => $result->perPage(),
                    'total' => $result->total(),
                ],
            ]);
        }

        $tokens = $result->map($mapItem)->values()->all();

        return response()->json(['tokens' => $tokens]);
    }

    /**
     * Assign token(s) to program. Idempotent (syncWithoutDetaching).
     * POST /api/admin/programs/{program}/tokens
     * Body: { "token_id": <id> } or { "token_ids": [<id>, ...] }
     */
    public function store(AssignTokensRequest $request, Program $program): JsonResponse
    {
        $this->ensureProgramInSite($request, $program);

        $tokenIds = $request->getTokenIds();
        $result = $this->programTokenService->assign($program, $tokenIds);

        $status = count($result['attached']) > 0 ? 201 : 200;

        return response()->json([
            'message' => count($result['attached']) === 1
                ? 'Token assigned to program.'
                : count($result['attached']).' tokens assigned to program.',
            'token_ids' => $result['attached'],
        ], $status);
    }

    /**
     * Unassign token from program. Detach only; no change to token status or sessions. Idempotent.
     * DELETE /api/admin/programs/{program}/tokens/{token}
     */
    public function destroy(Request $request, Program $program, Token $token): JsonResponse
    {
        $this->ensureProgramInSite($request, $program);

        $this->programTokenService->unassign($program, $token);

        return response()->json(null, 204);
    }

    /**
     * Bulk assign by physical_id pattern (e.g. "A*" -> LIKE "A%").
     * POST /api/admin/programs/{program}/tokens/bulk
     * Body: { "pattern": "A*" }
     */
    public function bulkStore(BulkAssignTokensRequest $request, Program $program): JsonResponse
    {
        $this->ensureProgramInSite($request, $program);

        $likePattern = $request->getLikePattern();
        $result = $this->programTokenService->bulkAssignByPattern($program, $likePattern);

        return response()->json([
            'message' => $result['count'] === 1
                ? '1 token assigned to program.'
                : $result['count'].' tokens assigned to program.',
            'count' => $result['count'],
            'added_count' => $result['count'],
            'token_ids' => $result['token_ids'],
        ], 200);
    }

    /**
     * Per central-edge B.4: ensure program belongs to admin's site. 404 if not.
     */
    private function ensureProgramInSite(Request $request, Program $program): void
    {
        $siteId = $request->user()->site_id;
        if ($siteId === null) {
            abort(403, 'You must be assigned to a site to access this resource.');
        }
        if ($program->site_id !== $siteId) {
            abort(404);
        }
    }

    private function tokenResource(Token $token): array
    {
        return [
            'id' => $token->id,
            'physical_id' => $token->physical_id,
            'pronounce_as' => $token->pronounce_as ?? 'letters',
            'status' => $token->status,
            'tts_status' => $token->tts_status,
        ];
    }

    private function tokenResourceWithSource(Token $token, string $source, bool $canUnassign): array
    {
        return array_merge($this->tokenResource($token), [
            'is_global' => (bool) $token->is_global,
            'source' => $source,
            'can_unassign' => $canUnassign,
        ]);
    }
}
