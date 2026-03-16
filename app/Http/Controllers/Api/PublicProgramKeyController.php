<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PublicProgramKeyRequest;
use App\Models\Program;
use App\Models\ProgramAccessToken;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * Per addition-to-public-site-plan Part 5.1: validate program key, issue temporary access token.
 * No auth. Throttled 10/min per IP. Requires site in known_sites cookie first.
 */
class PublicProgramKeyController extends Controller
{
    private const COOKIE_NAME = 'known_sites';

    public function store(PublicProgramKeyRequest $request): JsonResponse
    {
        $siteSlug = $request->validated('site_slug');
        $key = $this->normalizeKey((string) $request->validated('key'));
        $programSlug = $request->validated('program_slug');
        if (is_string($programSlug)) {
            $programSlug = trim($programSlug);
            $programSlug = $programSlug === '' ? null : $programSlug;
        } else {
            $programSlug = null;
        }

        $site = Site::query()->where('slug', $siteSlug)->first();
        if (! $site) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $known = $this->parseKnownSites($request->cookie(self::COOKIE_NAME));
        $slugs = array_column($known, 'slug');
        if (! in_array($site->slug, $slugs, true)) {
            return response()->json(['message' => 'Site access required first.'], 403);
        }

        $program = null;

        if ($programSlug !== null) {
            $candidate = Program::query()
                ->where('site_id', $site->id)
                ->where('slug', $programSlug)
                ->where('is_active', true)
                ->first();

            if ($candidate !== null && $this->keyMatches($candidate, $key)) {
                $program = $candidate;
            }
        }

        if ($program === null) {
            $program = Program::query()
                ->where('site_id', $site->id)
                ->where('is_active', true)
                ->get()
                ->first(fn (Program $p) => $this->keyMatches($p, $key));
        }

        if (! $program) {
            return response()->json(['message' => 'Invalid key.'], 422);
        }

        $expiryHours = $program->settings()->getPublicAccessExpiryHours();
        $expiresAt = now()->addHours($expiryHours);
        $rawToken = Str::random(32);
        $hash = hash('sha256', $rawToken);

        ProgramAccessToken::create([
            'program_id' => $program->id,
            'token_hash' => $hash,
            'expires_at' => $expiresAt,
        ]);

        return response()->json([
            'program_slug' => $program->slug,
            'site_slug' => $site->slug,
            'program_name' => $program->name,
            'token' => $rawToken,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    /**
     * @return list<array{slug: string, name: string}>
     */
    private function parseKnownSites(?string $value): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        if (! is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $item) {
            if (is_array($item) && ! empty($item['slug']) && is_string($item['slug'])) {
                $out[] = [
                    'slug' => $item['slug'],
                    'name' => isset($item['name']) && is_string($item['name']) ? $item['name'] : $item['slug'],
                ];
            }
        }
        return $out;
    }

    /** Normalize key for comparison: trim and ensure string (handles any request/encoding quirks). */
    private function normalizeKey(string $key): string
    {
        return trim($key);
    }

    /** True if program has a non-empty public key that matches the given normalized key (case-insensitive). */
    private function keyMatches(Program $program, string $key): bool
    {
        $stored = $program->settings()->getPublicAccessKey();
        if ($stored === null || $stored === '') {
            return false;
        }
        $stored = trim((string) $stored);
        if ($stored === '') {
            return false;
        }

        return strcasecmp($stored, $key) === 0;
    }
}
