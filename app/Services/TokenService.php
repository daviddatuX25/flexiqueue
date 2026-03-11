<?php

namespace App\Services;

use App\Models\Token;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Per 08-API-SPEC-PHASE1 §5.5: batch token creation. Each token gets unique qr_code_hash (per 04-DATA-MODEL).
 * Per docs/REFACTORING-ISSUE-LIST.md Issue 4: token lookup by physical_id or qr_hash lives here.
 */
class TokenService
{
    /**
     * Look up a token by qr_code_hash or physical_id. Precedence: qr_hash first, then physical_id.
     * Per docs/REFACTORING-ISSUE-LIST.md Issue 4.
     *
     * @return Token|null The token if found, null otherwise or when both inputs are empty
     */
    public function lookupByPhysicalOrHash(?string $physicalId, ?string $qrHash): ?Token
    {
        $qrHash = trim($qrHash ?? '') !== '' ? trim($qrHash) : null;
        $physicalId = trim($physicalId ?? '') !== '' ? trim($physicalId) : null;

        if ($qrHash !== null) {
            return Token::where('qr_code_hash', $qrHash)->first();
        }
        if ($physicalId !== null) {
            return Token::where('physical_id', $physicalId)->first();
        }

        return null;
    }

    /**
     * Create a batch of tokens. physical_id = prefix + (start_number + i). qr_code_hash is unique per token.
     * pronounce_as: 'letters' (e.g. "A 3") or 'word' (e.g. "A3") for TTS.
     *
     * @return array{created: int, tokens: array<int, array>}
     */
    public function batchCreate(string $prefix, int $count, int $startNumber, string $pronounceAs = 'letters'): array
    {
        if ($count <= 0) {
            return [
                'created' => 0,
                'tokens' => [],
            ];
        }

        $now = now();
        $rows = [];
        $hashes = [];
        $normalizedPronounceAs = in_array($pronounceAs, ['letters', 'word'], true) ? $pronounceAs : 'letters';

        for ($i = 0; $i < $count; $i++) {
            $num = $startNumber + $i;
            $physicalId = $prefix.(string) $num;
            $hash = hash('sha256', Str::random(40).$physicalId.microtime());

            $rows[] = [
                'qr_code_hash' => $hash,
                'physical_id' => $physicalId,
                'pronounce_as' => $normalizedPronounceAs,
                'status' => 'available',
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $hashes[] = $hash;
        }

        $tokens = DB::transaction(function () use ($rows, $hashes, $prefix, $startNumber, $count) {
            DB::table('tokens')->insert($rows);

            $startPhysicalId = $prefix.(string) $startNumber;
            $endPhysicalId = $prefix.(string) ($startNumber + $count - 1);

            /** @var \Illuminate\Support\Collection<int, Token> $inserted */
            $inserted = Token::query()
                ->whereBetween('physical_id', [$startPhysicalId, $endPhysicalId])
                ->whereIn('qr_code_hash', $hashes)
                ->orderBy('physical_id')
                ->get();

            return $inserted->all();
        });

        return [
            'created' => count($tokens),
            'tokens' => array_map(fn (Token $t) => $this->tokenResource($t), $tokens),
        ];
    }

    public function tokenResource(Token $token): array
    {
        return [
            'id' => $token->id,
            'physical_id' => $token->physical_id,
            'pronounce_as' => $token->pronounce_as ?? 'letters',
            'qr_code_hash' => $token->qr_code_hash,
            'status' => $token->status,
            'tts_status' => $token->tts_status,
            'has_tts_audio' => $token->tts_audio_path !== null,
        ];
    }
}
