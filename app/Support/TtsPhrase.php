<?php

namespace App\Support;

use App\Models\Token;

/**
 * Build TTS phrase for a token — same logic as frontend aliasForSpeech so pre-generated audio matches.
 * Phrase: "Calling {alias_spoken}, please proceed to your station".
 */
class TtsPhrase
{
    /** Letter → phonetic word (matches resources/js/Pages/Display/Board.svelte LETTER_PHONETIC). */
    private const LETTER_PHONETIC = [
        'a' => 'ay', 'b' => 'bee', 'c' => 'see', 'd' => 'dee', 'e' => 'ee', 'f' => 'eff',
        'g' => 'jee', 'h' => 'aych', 'i' => 'eye', 'j' => 'jay', 'k' => 'kay', 'l' => 'ell',
        'm' => 'em', 'n' => 'en', 'o' => 'oh', 'p' => 'pee', 'q' => 'cue', 'r' => 'ar',
        's' => 'ess', 't' => 'tee', 'u' => 'you', 'v' => 'vee', 'w' => 'double you',
        'x' => 'ex', 'y' => 'why', 'z' => 'zee',
    ];

    /**
     * Build the call phrase for a token (generic "your station" so no station needed at create time).
     */
    public static function buildCallPhraseForToken(Token $token): string
    {
        $aliasSpoken = self::aliasForSpeech(
            $token->physical_id ?? 'client',
            $token->pronounce_as ?? 'letters'
        );

        return 'Calling '.$aliasSpoken.', please proceed to your station';
    }

    /**
     * Alias text for TTS: letters → phonetic + digit runs; word = as-is. Matches frontend aliasForSpeech.
     */
    public static function aliasForSpeech(string $alias, string $pronounceAs = 'letters'): string
    {
        $raw = trim($alias) !== '' ? trim($alias) : 'client';
        if ($pronounceAs === 'word') {
            return $raw;
        }

        $segments = [];
        $i = 0;
        $len = strlen($raw);

        while ($i < $len) {
            $c = $raw[$i];
            if (preg_match('/[a-zA-Z]/', $c)) {
                $run = '';
                while ($i < $len && preg_match('/[a-zA-Z]/', $raw[$i])) {
                    $run .= $raw[$i];
                    $i++;
                }
                for ($j = 0; $j < strlen($run); $j++) {
                    $lower = strtolower($run[$j]);
                    if (isset(self::LETTER_PHONETIC[$lower])) {
                        $segments[] = self::LETTER_PHONETIC[$lower];
                    }
                }
            } elseif (preg_match('/\d/', $c)) {
                $run = '';
                while ($i < $len && preg_match('/\d/', $raw[$i])) {
                    $run .= $raw[$i];
                    $i++;
                }
                $segments[] = $run;
            } else {
                $i++;
            }
        }

        return $segments !== [] ? implode(' ', $segments) : $raw;
    }
}
