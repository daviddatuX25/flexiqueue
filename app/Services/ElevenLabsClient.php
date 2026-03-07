<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * ElevenLabs API client. Uses xi-api-key header per ElevenLabs documentation.
 * @see https://elevenlabs.io/docs/api-reference/authentication
 */
class ElevenLabsClient
{
    private const BASE_URL = 'https://api.elevenlabs.io/v1';

    public function __construct(
        private readonly string $apiKey
    ) {
    }

    /**
     * Validate API key via GET /v1/user. Returns true if key is valid.
     */
    public function validateKey(): bool
    {
        $response = Http::withHeaders($this->headers())
            ->timeout(10)
            ->get(self::BASE_URL.'/user');

        return $response->successful();
    }

    /**
     * Fetch voices from GET /v1/voices.
     * Per ElevenLabs: returns { voices: [{ voice_id, name, labels, ... }] }
     *
     * @return array<array{voice_id: string, name: string, labels?: array, category?: string}>
     */
    public function getVoices(bool $showLegacy = false): array
    {
        $query = $showLegacy ? ['show_legacy' => 'true'] : [];
        $response = Http::withHeaders($this->headers())
            ->timeout(15)
            ->get(self::BASE_URL.'/voices', $query);

        if (! $response->successful()) {
            return [];
        }

        $json = $response->json();
        $voices = $json['voices'] ?? [];

        return is_array($voices) ? $voices : [];
    }

    /**
     * Fetch subscription and character quota via GET /v1/user/subscription.
     * Returns character_count, character_limit, next_character_count_reset_unix, tier, etc.
     *
     * @return array{character_count: int, character_limit: int, next_character_count_reset_unix?: int, tier?: string}|null
     */
    public function getSubscription(): ?array
    {
        $response = Http::withHeaders($this->headers())
            ->timeout(10)
            ->get(self::BASE_URL.'/user/subscription');

        if (! $response->successful()) {
            return null;
        }

        $json = $response->json();
        if (! is_array($json)) {
            return null;
        }

        return [
            'character_count' => (int) ($json['character_count'] ?? 0),
            'character_limit' => (int) ($json['character_limit'] ?? 0),
            'next_character_count_reset_unix' => isset($json['next_character_count_reset_unix'])
                ? (int) $json['next_character_count_reset_unix']
                : null,
            'tier' => $json['tier'] ?? null,
        ];
    }

    /**
     * Fetch character usage time-series via GET /v1/usage/character-stats.
     * start_unix and end_unix are in milliseconds (UTC).
     *
     * @return array{time: int[], usage: array<string, float[]>}|null
     */
    public function getUsageStats(int $startUnixMs, int $endUnixMs, string $aggregation = 'day'): ?array
    {
        $query = [
            'start_unix' => $startUnixMs,
            'end_unix' => $endUnixMs,
            'aggregation_interval' => $aggregation,
        ];

        $response = Http::withHeaders($this->headers())
            ->timeout(15)
            ->get(self::BASE_URL.'/usage/character-stats', $query);

        if (! $response->successful()) {
            return null;
        }

        $json = $response->json();
        if (! is_array($json)) {
            return null;
        }

        $time = $json['time'] ?? [];
        $usage = $json['usage'] ?? [];

        return [
            'time' => is_array($time) ? array_map('intval', $time) : [],
            'usage' => is_array($usage) ? $usage : [],
        ];
    }

    /**
     * Headers per ElevenLabs API: xi-api-key required.
     */
    private function headers(): array
    {
        return [
            'xi-api-key' => $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }
}
