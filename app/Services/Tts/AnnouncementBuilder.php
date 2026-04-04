<?php

namespace App\Services\Tts;

use App\Models\Program;
use App\Models\Station;
use App\Models\Token;
use App\Models\TokenTtsSetting;
use App\Support\TtsPhrase;

/**
 * Single place to assemble token call (site + token) and station directions (program + station) announcement text.
 * Used by TTS jobs, display payloads, admin samples, and broadcast token body helpers.
 */
class AnnouncementBuilder
{
    /**
     * Merge site defaults with token overrides for a language.
     * When allow_custom_pronunciation is off, token_phrase is stripped so stored overrides are ignored.
     *
     * @return array<string, mixed>
     */
    public function mergeLangConfig(TokenTtsSetting $site, Token $token, string $lang): array
    {
        $defaults = $site->getDefaultLanguages();

        $merged = array_merge($defaults[$lang] ?? [], $token->getTtsConfigFor($lang));

        if (! $site->getPlayback()['allow_custom_pronunciation']) {
            unset($merged['token_phrase']);
        }

        return $merged;
    }

    /**
     * Spoken token body only (no pre-phrase / tail). Matches TtsPhrase rules for merged config.
     *
     * @param  array<string, mixed>  $mergedLangConfig
     */
    public function spokenTokenPart(Token $token, string $lang, array $mergedLangConfig): string
    {
        return TtsPhrase::tokenSpokenPartFromMergedConfig($token, $lang, $mergedLangConfig);
    }

    /**
     * Full segment 1 text for a token in a language.
     *
     * @param  array<string, mixed>|null  $mergedLangConfig  When null, merges site + token for $lang.
     */
    public function buildSegment1(Token $token, TokenTtsSetting $site, string $lang, ?array $mergedLangConfig = null): string
    {
        $merged = $mergedLangConfig ?? $this->mergeLangConfig($site, $token, $lang);

        $segment2Enabled = (bool) (($site->getPlayback()['segment_2_enabled'] ?? false) === true);

        $pre = isset($merged['pre_phrase']) && is_string($merged['pre_phrase'])
            ? trim($merged['pre_phrase'])
            : '';
        $tail = isset($merged['token_bridge_tail']) && is_string($merged['token_bridge_tail'])
            ? trim($merged['token_bridge_tail'])
            : '';
        $body = $this->spokenTokenPart($token, $lang, $merged);

        // When station directions (segment 2) are enabled, the token-bridge tail is
        // spoken in segment 1 only when segment 2 is disabled. Ignore it here so
        // "Play full" never includes the tail/closing inside the call segment.
        if ($segment2Enabled) {
            $tail = '';
        }

        if ($pre === '' && $tail === '') {
            // Per-language fallback stored in default_languages.
            // If nothing is configured (empty string), return empty so callers can fall back safely.
            if ($segment2Enabled) {
                // When segment 2 is enabled, we want a short token call without the
                // "please proceed to your station" closing. Segment 2 provides the
                // directions instead.
                return trim('Calling '.$body);
            }
            $fallback = isset($merged['segment1_no_pre_tail_fallback']) && is_string($merged['segment1_no_pre_tail_fallback'])
                ? trim($merged['segment1_no_pre_tail_fallback'])
                : '';
            if ($fallback === '') {
                return '';
            }

            return str_replace(['{token}', '{body}'], $body, $fallback);
        }

        $lead = $pre === '' ? 'Calling' : $pre;
        $parts = array_filter([$lead, $body, $tail], fn (string $s): bool => $s !== '');

        return trim(preg_replace('/\s+/', ' ', implode(' ', $parts)));
    }

    public function buildClosingWhenSegment2Disabled(TokenTtsSetting $site, string $lang): string
    {
        $row = $site->getDefaultLanguages()[$lang] ?? [];
        $raw = $row['closing_without_segment2'] ?? '';

        return is_string($raw) ? trim($raw) : '';
    }

    /**
     * Station directions: program connector + station phrase or station name.
     */
    public function buildSegment2(Station $station, Program $program, string $lang, TokenTtsSetting $site): string
    {
        $connector = $this->resolveConnectorPhrase($program, $lang);
        $stationCustom = $this->resolveStationTtsPhrase($station, $lang, $site);

        return $this->joinSegment2Strings($connector, $stationCustom, $station->name);
    }

    /**
     * Segment 2 from explicit parts (admin preview when no station model is loaded).
     */
    public function buildSegment2FromParts(?string $connectorPhrase, ?string $stationPhrase, string $stationName): string
    {
        return $this->joinSegment2Strings($connectorPhrase, $stationPhrase, $stationName);
    }

    /**
     * @return array<string, string> en, fil, ilo → spoken token body (for StationActivity / display fallback).
     */
    public function tokenSpokenByLangForBroadcast(Token $token, TokenTtsSetting $site): array
    {
        $out = [];
        foreach (['en', 'fil', 'ilo'] as $lang) {
            $merged = $this->mergeLangConfig($site, $token, $lang);
            $out[$lang] = $this->spokenTokenPart($token, $lang, $merged);
        }

        return $out;
    }

    public function resolveConnectorPhrase(Program $program, string $lang): ?string
    {
        $settings = $program->settings ?? [];
        if (
            ! isset($settings['tts']['connector']['languages'][$lang])
            || ! is_array($settings['tts']['connector']['languages'][$lang])
        ) {
            return null;
        }
        $raw = $settings['tts']['connector']['languages'][$lang]['connector_phrase'] ?? null;

        return is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
    }

    public function resolveStationTtsPhrase(Station $station, string $lang, TokenTtsSetting $site): ?string
    {
        if (! $site->getPlayback()['allow_custom_pronunciation']) {
            return null;
        }

        $settings = $station->settings ?? [];
        $languages = $settings['tts']['languages'] ?? [];
        $config = $languages[$lang] ?? null;
        if (! is_array($config)) {
            return null;
        }
        $phrase = $config['station_phrase'] ?? null;

        return is_string($phrase) && trim($phrase) !== '' ? trim($phrase) : null;
    }

    private function joinSegment2Strings(?string $connectorPhrase, ?string $stationPhrase, string $stationName): string
    {
        // Segment 2 should be connector-driven.
        // If the connector is not configured for this language, return empty (no English fallback).
        if ($connectorPhrase === null || trim($connectorPhrase) === '') {
            return '';
        }

        $stationPart = $stationPhrase !== null && $stationPhrase !== ''
            ? $stationPhrase
            : $stationName;

        return trim($connectorPhrase.' '.$stationPart);
    }
}
