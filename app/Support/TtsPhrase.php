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
        'a' => 'ay', 'b' => 'bee', 'c' => 'see', 'd' => 'dee', 'e' => 'ee', 'f' => 'eff',
        'g' => 'jee', 'h' => 'aych', 'i' => 'eye', 'j' => 'jay', 'k' => 'kay', 'l' => 'ell',
        'm' => 'em', 'n' => 'en', 'o' => 'oh', 'p' => 'pee', 'q' => 'cue', 'r' => 'ar',
        's' => 'ess', 't' => 'tee', 'u' => 'you', 'v' => 'vee', 'w' => 'double you',
        'x' => 'ex', 'y' => 'why', 'z' => 'zee',
    ];

    /** Ilocano: 'a' => 'eyy' so "A" is spoken clearly (not like "eye"). Other letters fall back to English. */
    private const LETTER_PHONETIC_ILO = [
        'a' => 'eyy', 'h' => 'eych', 'k' => 'khey', 'o' => 'ow',
    ];

    /** Filipino/Tagalog: same as Ilocano for letter A. */
    private const LETTER_PHONETIC_FIL = [
        'a' => 'eyy', 'h' => 'eych', 'k' => 'khey', 'o' => 'ow',
    ];

    /**
     * Spoken token body for a language.
     * - custom: per-lang `token_phrase` when non-empty (spoken exactly as entered); else same as word split.
     * - word: contiguous letter runs as one spoken chunk each, then digit runs (e.g. AAB3 → "AAB 3"); ignores `token_phrase`.
     * - letters: letter phonetics + digit runs (ignores `token_phrase`).
     *
     * @param  array<string, mixed>  $mergedLangConfig  Global default + token override for this language.
     */
    public static function tokenSpokenPartFromMergedConfig(Token $token, string $lang, array $mergedLangConfig): string
    {
        $pronounceAs = $token->pronounce_as ?? 'letters';

        if ($pronounceAs === 'custom') {
            $raw = isset($mergedLangConfig['token_phrase']) && is_string($mergedLangConfig['token_phrase'])
                ? trim($mergedLangConfig['token_phrase'])
                : '';
            if ($raw !== '') {
                return $raw;
            }

            return self::aliasWordLetterRunsAndDigits($token->physical_id ?? 'client');
        }

        return self::aliasForSpeech(
            $token->physical_id ?? 'client',
            $pronounceAs === 'word' ? 'word' : 'letters',
            $lang
        );
    }

    /**
     * Build the call phrase for a token (generic "your station" so no station needed at create time).
     *
     * @param  array<string, mixed>  $mergedLangConfig  Optional merged row for $lang (for token_phrase override).
     */
    public static function buildCallPhraseForToken(Token $token, ?string $lang = null, array $mergedLangConfig = []): string
    {
        $langKey = $lang ?? 'en';
        $aliasSpoken = self::tokenSpokenPartFromMergedConfig($token, $langKey, $mergedLangConfig);

        return 'Calling '.$aliasSpoken.', please proceed to your station';
    }

    /**
     * Full sample phrase for a given language and config (pre_phrase + body or full call phrase).
     * Optional $tokenPhraseOverride matches per-lang `token_phrase` (spoken body instead of alias phonetics).
     */
    public static function getSamplePhrase(string $prePhrase, string $alias, string $pronounceAs, string $lang, ?string $tokenPhraseOverride = null): string
    {
        $useOverride = $pronounceAs === 'custom'
            && $tokenPhraseOverride !== null
            && trim($tokenPhraseOverride) !== '';
        $body = $useOverride
            ? trim($tokenPhraseOverride)
            : (
                ($pronounceAs === 'word' || $pronounceAs === 'custom')
                    ? self::aliasWordLetterRunsAndDigits($alias !== '' ? $alias : 'A1')
                    : self::aliasForSpeech($alias !== '' ? $alias : 'A1', 'letters', $lang)
            );
        $prePhrase = trim($prePhrase);
        if ($prePhrase !== '') {
            return trim($prePhrase.' '.$body);
        }

        return 'Calling '.$body.', please proceed to your station';
    }

    /**
     * Alias text for TTS: letters → phonetic per letter + digit runs; word → letter runs as chunks + digit runs. Matches frontend aliasForSpeech.
     *
     * @param  'en'|'fil'|'ilo'|null  $lang  When set, use language-specific letter phonetics (e.g. Ilocano 'a' => 'ah').
     */
    public static function aliasForSpeech(string $alias, string $pronounceAs = 'letters', ?string $lang = null): string
    {
        $raw = trim($alias) !== '' ? trim($alias) : 'client';
        if ($pronounceAs === 'word') {
            return self::aliasWordLetterRunsAndDigits($raw);
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
     * Word mode: each contiguous [A-Za-z]+ is one spoken unit; each digit run stays numeric (e.g. AAB3 → "AAB 3").
     */
    private static function aliasWordLetterRunsAndDigits(string $raw): string
    {
        $raw = trim($raw) !== '' ? trim($raw) : 'client';
        $parts = [];
        $len = strlen($raw);
        $i = 0;
        while ($i < $len) {
            $c = $raw[$i];
            if (preg_match('/[a-zA-Z]/', $c)) {
                $run = '';
                while ($i < $len && preg_match('/[a-zA-Z]/', $raw[$i])) {
                    $run .= $raw[$i];
                    $i++;
                }
                $parts[] = $run;
            } elseif (preg_match('/\d/', $c)) {
                $run = '';
                while ($i < $len && preg_match('/\d/', $raw[$i])) {
                    $run .= $raw[$i];
                    $i++;
                }
                $parts[] = $run;
            } else {
                $i++;
            }
        }

        return $parts !== [] ? implode(' ', $parts) : $raw;
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
