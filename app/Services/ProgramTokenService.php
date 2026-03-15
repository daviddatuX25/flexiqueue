<?php

namespace App\Services;

use App\Models\Program;
use App\Models\Token;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as LengthAwarePaginatorConcrete;
use Illuminate\Support\Collection;

/**
 * Per central-edge C.3: assign/unassign tokens to programs via program_token pivot.
 * No side effects on token status or queue_sessions; idempotent attach/detach.
 */
class ProgramTokenService
{
    /**
     * Assign tokens to program. Each token can only be assigned to one program at a time:
     * assigning to this program replaces any existing program assignment for that token.
     * Global tokens (is_global) can still be used by any program without being in program_token.
     *
     * @param  array<int, int>  $tokenIds
     * @return array{attached: array<int, int>}
     */
    public function assign(Program $program, array $tokenIds): array
    {
        if ($tokenIds === []) {
            return ['attached' => []];
        }

        $tokens = Token::query()->whereIn('id', $tokenIds)->get();
        foreach ($tokens as $token) {
            $token->programs()->sync([$program->id]);
        }

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
     * Bulk assign by physical_id LIKE pattern. Per site-scoping-migration-spec §2: only tokens in program's site.
     * Each token is assigned only to this program (any existing assignment is replaced).
     *
     * @return array{count: int, token_ids: array<int, int>}
     */
    public function bulkAssignByPattern(Program $program, string $likePattern): array
    {
        $tokens = Token::query()
            ->where('site_id', $program->site_id)
            ->where('physical_id', 'like', $likePattern)
            ->get();

        foreach ($tokens as $token) {
            $token->programs()->sync([$program->id]);
        }

        $tokenIds = $tokens->pluck('id')->all();

        return [
            'count' => count($tokenIds),
            'token_ids' => array_values($tokenIds),
        ];
    }

    /**
     * List tokens for program: assigned (program_token) + optionally global (site tokens with is_global).
     * Each item has token, source ('assigned'|'global'), and can_unassign (true only for assigned).
     *
     * @param  bool  $assignedOnly  when true, only return tokens assigned to this program (exclude global)
     * @return Collection<int, array{token: Token, source: string, can_unassign: bool}>|LengthAwarePaginator
     */
    public function listTokensForProgram(Program $program, int $perPage = 0, bool $assignedOnly = false): LengthAwarePaginator|Collection
    {
        $assigned = $program->tokens()->orderBy('physical_id')->get();
        $assignedIds = $assigned->pluck('id')->all();

        $globalOnly = collect();
        if (! $assignedOnly && $program->site_id !== null) {
            $globalOnly = Token::query()
                ->where('site_id', $program->site_id)
                ->where('is_global', true)
                ->whereNotIn('id', $assignedIds)
                ->orderBy('physical_id')
                ->get();
        }

        $combined = $assigned->map(fn (Token $t) => [
            'token' => $t,
            'source' => 'assigned',
            'can_unassign' => true,
        ])->concat(
            $globalOnly->map(fn (Token $t) => [
                'token' => $t,
                'source' => 'global',
                'can_unassign' => false,
            ])
        )->sortBy(fn (array $item) => $item['token']->physical_id)->values();

        if ($perPage > 0) {
            $page = (int) request()->input('page', 1);
            $total = $combined->count();
            $items = $combined->forPage($page, $perPage)->values()->all();

            return new LengthAwarePaginatorConcrete(
                $items,
                $total,
                $perPage,
                $page,
                ['path' => request()->url(), 'query' => request()->query()]
            );
        }

        return $combined;
    }
}
