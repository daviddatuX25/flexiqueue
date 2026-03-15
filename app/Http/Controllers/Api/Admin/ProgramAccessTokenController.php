<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminActionLog;
use App\Models\Program;
use App\Models\ProgramAccessToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per addition-to-public-site-plan Addition B: list and revoke program access tokens.
 */
class ProgramAccessTokenController extends Controller
{
    /**
     * GET /api/admin/programs/{program}/access-tokens — list active tokens with token_ref (last 4 of hash).
     */
    public function index(Request $request, Program $program): JsonResponse
    {
        $this->ensureProgramInSite($request, $program);

        $tokens = ProgramAccessToken::query()
            ->where('program_id', $program->id)
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->get();

        $items = $tokens->map(fn (ProgramAccessToken $t) => [
            'id' => $t->id,
            'token_ref' => substr($t->token_hash, -4),
            'issued_at' => $t->created_at?->toIso8601String(),
            'expires_at' => $t->expires_at->toIso8601String(),
        ])->values()->all();

        return response()->json([
            'active_count' => $tokens->count(),
            'tokens' => $items,
        ]);
    }

    /**
     * DELETE /api/admin/programs/{program}/access-tokens — revoke all tokens.
     */
    public function destroyAll(Request $request, Program $program): JsonResponse
    {
        $this->ensureProgramInSite($request, $program);

        $count = ProgramAccessToken::query()->where('program_id', $program->id)->delete();

        AdminActionLog::log($request->user()->id, 'program_access_tokens_revoked', 'Program', $program->id, ['revoked_count' => $count]);

        return response()->json(['message' => 'Revoked.', 'revoked_count' => $count]);
    }

    /**
     * DELETE /api/admin/programs/{program}/access-tokens/{token} — revoke one token.
     */
    public function destroy(Request $request, Program $program, ProgramAccessToken $token): JsonResponse
    {
        $this->ensureProgramInSite($request, $program);

        if ($token->program_id !== $program->id) {
            abort(404);
        }

        $token->delete();

        return response()->json(['message' => 'Revoked.']);
    }

    private function ensureProgramInSite(Request $request, Program $program): void
    {
        $siteId = $request->user()?->site_id;
        if ($siteId === null) {
            abort(403, 'You must be assigned to a site to access this resource.');
        }
        if ($program->site_id !== $siteId) {
            abort(404);
        }
    }
}
