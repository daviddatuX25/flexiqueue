<?php

namespace App\Support;

use App\Models\Token;

/**
 * Build TTS phrase fragments for tokens so server-side pre-generated audio
 * matches the display board behavior.
 *
 * This helper is responsible for the **token call** part only
 * (e.g. "Calling A one …"). Any connector + station/window phrasing is
 * handled separately via Program/Station TTS settings on the display side.
 */
class TtsPhrase
{
    /** Letter → phonetic word (matches resources/js/Pages/Display/Board.svelte LETTER_PHONETIC). */
    private const LETTER_PHONETIC = [
        'a' => 'ei,', 'b' => 'bee', 'c' => 'see', 'd' => 'dee', 'e' => 'ee', 'f' => 'eff',
        'g' => 'jee', 'h' => 'aych', 'i' => 'eye', 'j' => 'jay', 'k' => 'kay', 'l' => 'ell',
        'm' => 'em', 'n' => 'en', 'o' => 'oh', 'p' => 'pee', 'q' => 'cue', 'r' => 'ar',
        's' => 'ess', 't' => 'tee', 'u' => 'you', 'v' => 'vee', 'w' => 'double you',
        'x' => 'ex', 'y' => 'why', 'z' => 'zee',
    ];

    /** Ilocano: 'a' => 'eyy' so "A" is spoken clearly (not like "eye"). Other letters fall back to English. */
    private const LETTER_PHONETIC_ILO = [
        'a' => 'ey-' ,'h' => 'eych', 'k' => 'khey', 'o' => 'ow',
    ];

    /** Filipino/Tagalog: same as Ilocano for letter A. */
    private const LETTER_PHONETIC_FIL = [
        'a' => 'ey-','h' => 'eych', 'k' => 'khey', 'o' => 'ow',
    ];

    /**
     * Build the call phrase for a token (generic "your station" so no station needed at create time).
     */
    public static function buildCallPhraseForToken(Token $token, ?string $lang = null): string
    {
        $aliasSpoken = self::aliasForSpeech(
            $token->physical_id ?? 'client',
            $token->pronounce_as ?? 'letters',
            $lang
        );

        return 'Calling '.$aliasSpoken.', please proceed to your station';
    }

    /**
     * Full sample phrase for a given language and config (pre_phrase + alias or full call phrase).
     */
    public static function getSamplePhrase(string $prePhrase, string $alias, string $pronounceAs, string $lang): string
    {
        $prePhrase = trim($prePhrase);
        if ($prePhrase !== '') {
            return trim($prePhrase.' '.self::aliasForSpeech($alias ?: 'A1', $pronounceAs, $lang));
        }

        return 'Calling '.self::aliasForSpeech($alias ?: 'A1', $pronounceAs, $lang).', please proceed to your station';
    }

    /**
     * Alias text for TTS: letters → phonetic + digit runs; word = as-is. Matches frontend aliasForSpeech.
     *
     * @param  'en'|'fil'|'ilo'|null  $lang  When set, use language-specific letter phonetics (e.g. Ilocano 'a' => 'ah').
     */
    public static function aliasForSpeech(string $alias, string $pronounceAs = 'letters', ?string $lang = null): string
    {
        $raw = trim($alias) !== '' ? trim($alias) : 'client';
        if ($pronounceAs === 'word') {
            return $raw;
        }

        $map = self::letterPhoneticForLang($lang);
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
                    if (isset($map[$lower])) {
                        $segments[] = $map[$lower];
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

    /**
     * @return array<string, string>
     */
    private static function letterPhoneticForLang(?string $lang): array
    {
        $base = self::LETTER_PHONETIC;
        if ($lang === 'ilo') {
            return array_merge($base, self::LETTER_PHONETIC_ILO);
        }
        if ($lang === 'fil') {
            return array_merge($base, self::LETTER_PHONETIC_FIL);
        }

        return $base;
    }
}
