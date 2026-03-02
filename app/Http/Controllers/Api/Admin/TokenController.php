<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\BatchCreateTokenRequest;
use App\Http\Requests\BatchDeleteTokenRequest;
use App\Http\Requests\UpdateTokenRequest;
use App\Models\Token;
use App\Services\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per 08-API-SPEC-PHASE1 §5.5: Token list, batch create, update status. Auth: role:admin.
 */
class TokenController extends Controller
{
    public function __construct(
        private TokenService $tokenService
    ) {}

    /**
     * List tokens. Filterable: ?status=available|in_use, ?search= (physical_id substring).
     * Soft-deleted tokens excluded by default.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Token::query()->orderBy('physical_id');

        if ($request->has('status') && $request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->has('search') && $request->filled('search')) {
            $query->where('physical_id', 'like', '%'.$request->input('search').'%');
        }

        $tokens = $query->get()->map(fn (Token $t) => $this->tokenResource($t));

        return response()->json(['tokens' => $tokens]);
    }

    /**
     * Create token batch. Per spec: 201 with created count and tokens array.
     */
    public function batch(BatchCreateTokenRequest $request): JsonResponse
    {
        $pronounceAs = $request->validated('pronounce_as') ?? 'letters';
        $result = $this->tokenService->batchCreate(
            $request->validated('prefix'),
            $request->validated('count'),
            $request->validated('start_number'),
            $pronounceAs
        );

        return response()->json($result, 201);
    }

    /**
     * Update token. Admin can set status (available/deactivated) and/or pronounce_as (letters/word).
     * Cannot deactivate a token that is in_use.
     */
    public function update(UpdateTokenRequest $request, Token $token): JsonResponse
    {
        $updates = [];

        if ($request->has('status')) {
            $status = $request->validated('status');
            if ($status === 'deactivated' && $token->status === 'in_use') {
                return response()->json([
                    'message' => 'Cannot deactivate token in use. Mark it available first.',
                ], 409);
            }
            $updates['status'] = $status;
            $updates['current_session_id'] = $status !== 'in_use' ? null : $token->current_session_id;
        }

        if ($request->has('pronounce_as')) {
            $updates['pronounce_as'] = $request->validated('pronounce_as');
        }

        if (! empty($updates)) {
            $token->update($updates);
            $token->refresh();
        }

        return response()->json(['token' => $this->tokenResource($token)]);
    }

    /**
     * Soft delete a single token. Fails with 409 if token is in_use.
     */
    public function destroy(Token $token): JsonResponse
    {
        if ($token->status === 'in_use') {
            return response()->json([
                'message' => 'Cannot delete token in use.',
            ], 409);
        }

        $token->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Soft delete multiple tokens. Fails with 409 if any are in_use.
     */
    public function batchDelete(BatchDeleteTokenRequest $request): JsonResponse
    {
        $ids = $request->validated('ids');
        $tokens = Token::query()->whereIn('id', $ids)->get();

        $inUse = $tokens->filter(fn (Token $t) => $t->status === 'in_use');
        if ($inUse->isNotEmpty()) {
            return response()->json([
                'message' => 'Cannot delete token(s) in use.',
                'in_use_ids' => $inUse->pluck('id')->values()->all(),
            ], 409);
        }

        $deleted = Token::query()->whereIn('id', $ids)->delete();

        return response()->json(['deleted' => $deleted]);
    }

    private function tokenResource(Token $token): array
    {
        return [
            'id' => $token->id,
            'physical_id' => $token->physical_id,
            'pronounce_as' => $token->pronounce_as ?? 'letters',
            'qr_code_hash' => $token->qr_code_hash,
            'status' => $token->status,
        ];
    }
}
