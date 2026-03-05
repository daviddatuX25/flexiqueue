<?php

namespace App\Services;

use App\Models\Token;
use Illuminate\Support\Str;

/**
 * Per 08-API-SPEC-PHASE1 §5.5: batch token creation. Each token gets unique qr_code_hash (per 04-DATA-MODEL).
 */
class TokenService
{
    /**
     * Create a batch of tokens. physical_id = prefix + (start_number + i). qr_code_hash is unique per token.
     * pronounce_as: 'letters' (e.g. "A 3") or 'word' (e.g. "A3") for TTS.
     *
     * @return array{created: int, tokens: array<int, array>}
     */
    public function batchCreate(string $prefix, int $count, int $startNumber, string $pronounceAs = 'letters'): array
    {
        $tokens = [];
        for ($i = 0; $i < $count; $i++) {
            $num = $startNumber + $i;
            $physicalId = $prefix.(string) $num;
            $hash = hash('sha256', Str::random(40).$physicalId.microtime());

            $token = new Token;
            $token->qr_code_hash = $hash;
            $token->physical_id = $physicalId;
            $token->pronounce_as = in_array($pronounceAs, ['letters', 'word'], true) ? $pronounceAs : 'letters';
            $token->status = 'available';
            $token->save();
            $tokens[] = $token;
        }

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
