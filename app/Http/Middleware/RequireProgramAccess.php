<?php

namespace App\Http\Middleware;

use App\Models\Program;
use App\Models\ProgramAccessToken;
use App\Models\Site;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per addition-to-public-site-plan Part 5.3: for public program routes, require valid program access.
 * If program is private, known_programs cookie must contain valid token (validated against DB).
 * Applied after RequireSiteAccess so site is already validated.
 */
class RequireProgramAccess
{
    private const COOKIE_NAME = 'known_programs';

    public function handle(Request $request, Closure $next): Response
    {
        $site = $request->route('site');
        $programSlug = $request->route('program_slug') ?? $request->route('program');

        if (! $site instanceof Site) {
            return $next($request);
        }

        $program = Program::query()
            ->where('site_id', $site->id)
            ->where('slug', $programSlug)
            ->first();

        if (! $program) {
            return $next($request);
        }

        if (! $program->settings()->isPrivate()) {
            return $next($request);
        }

        $entries = $this->parseKnownPrograms($request->cookie(self::COOKIE_NAME));
        $entry = null;
        foreach ($entries as $e) {
            if (isset($e['site_slug']) && $e['site_slug'] === $site->slug
                && isset($e['program_slug']) && $e['program_slug'] === $program->slug) {
                $entry = $e;
                break;
            }
        }

        if (! $entry || empty($entry['token'])) {
            return redirect()->to('/');
        }

        $hash = hash('sha256', $entry['token']);
        $tokenRecord = ProgramAccessToken::query()
            ->where('program_id', $program->id)
            ->where('token_hash', $hash)
            ->where('expires_at', '>', now())
            ->first();

        if (! $tokenRecord) {
            $pruned = $this->pruneEntry($entries, $site->slug, $program->slug);
            $cookie = cookie(
                self::COOKIE_NAME,
                json_encode($pruned),
                60 * 24 * 365,
                '/',
                null,
                $request->secure(),
                true,
                false,
                'lax'
            );

            return redirect()->to('/')->with('program_access_expired', true)->cookie($cookie);
        }

        return $next($request);
    }

    /**
     * Remove one program entry from the list (so invalid/expired token is not re-sent).
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private function pruneEntry(array $entries, string $siteSlug, string $programSlug): array
    {
        return array_values(array_filter($entries, function ($e) use ($siteSlug, $programSlug) {
            return ! (isset($e['site_slug']) && $e['site_slug'] === $siteSlug
                && isset($e['program_slug']) && $e['program_slug'] === $programSlug);
        }));
    }

    /**
     * @return list<array{site_slug?: string, program_slug?: string, token?: string, expires_at?: string}>
     */
    private function parseKnownPrograms(?string $value): array
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
            if (is_array($item)) {
                $out[] = $item;
            }
        }
        return $out;
    }
}
