<?php

namespace App\Services;

use App\Models\Program;
use App\Models\Token;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Per central-edge C.3: assign/unassign tokens to programs via program_token pivot.
 * No side effects on token status or queue_sessions; idempotent attach/detach.
 */
class ProgramTokenService
{
    /**
     * Assign tokens to program (idempotent). Does not change token status or sessions.
     *
     * @param  array<int, int>  $tokenIds
     * @return array{attached: array<int, int>}  IDs that were newly attached (for response; syncWithoutDetaching doesn't distinguish, so we return all that were synced)
     */
    public function assign(Program $program, array $tokenIds): array
    {
        if ($tokenIds === []) {
            return ['attached' => []];
        }

        $program->tokens()->syncWithoutDetaching($tokenIds);

        return ['attached' => $tokenIds];
    }

    /**
     * Unassign token from program (detach only). Idempotent if token not in program.
     */
    public function unassign(Program $program, Token $token): void
    {
        $program->tokens()->detach($token->id);
    }

    /**
     * Bulk assign by physical_id LIKE pattern. Tokens scoped by program's site: only tokens that exist
     * (no site_id on tokens table, so we match all tokens by pattern). Idempotent.
     *
     * @return array{count: int, token_ids: array<int, int>}
     */
    public function bulkAssignByPattern(Program $program, string $likePattern): array
    {
        $tokenIds = Token::query()
            ->where('physical_id', 'like', $likePattern)
            ->pluck('id')
            ->all();

        if ($tokenIds !== []) {
            $program->tokens()->syncWithoutDetaching($tokenIds);
        }

        return [
            'count' => count($tokenIds),
            'token_ids' => array_values($tokenIds),
        ];
    }

    /**
     * List tokens assigned to program (paginated). For admin program token list.
     *
     * @return LengthAwarePaginator<int, Token>|Collection<int, Token>
     */
    public function listTokensForProgram(Program $program, int $perPage = 0): LengthAwarePaginator|Collection
    {
        $query = $program->tokens()->orderBy('physical_id');

        if ($perPage > 0) {
            return $query->paginate($perPage);
        }

        return $query->get();
    }
}
