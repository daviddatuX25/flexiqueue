<?php

namespace App\Http\Controllers;

use App\Models\ProgramAccessToken;
use App\Models\Site;
use App\Models\SiteShortLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Per addition-to-public-site-plan Part 6.2 + Addition A.3: resolve /go/{code} to site or program destination.
 * When redirecting, we set known_sites cookie so the user is not prompted for site key — the link/QR identifies the site.
 */
class ShortLinkResolverController extends Controller
{
    private const COOKIE_KNOWN_SITES = 'known_sites';
    private const COOKIE_KNOWN_PROGRAMS = 'known_programs';
    private const KNOWN_SITES_MAX_AGE_DAYS = 365;

    public function resolve(Request $request, string $code): RedirectResponse
    {
        $link = SiteShortLink::query()->where('code', $code)->first();
        if (! $link) {
            return redirect()->to('/');
        }

        return match ($link->type) {
            SiteShortLink::TYPE_SITE_ENTRY => $this->resolveSiteEntry($request, $link),
            SiteShortLink::TYPE_PROGRAM_PUBLIC => $this->resolveProgramPublic($request, $link),
            SiteShortLink::TYPE_PROGRAM_PRIVATE => $this->resolveProgramPrivate($request, $link),
            default => redirect()->to('/'),
        };
    }

    private function resolveSiteEntry(Request $request, SiteShortLink $link): RedirectResponse
    {
        $site = $link->site;
        if (! $site) {
            return redirect()->to('/');
        }
        $redirect = redirect()->to('/site/'.$site->slug);
        return $this->redirectWithKnownSite($request, $site, $redirect);
    }

    private function resolveProgramPublic(Request $request, SiteShortLink $link): RedirectResponse
    {
        $site = $link->site;
        $program = $link->program;
        if (! $site || ! $program) {
            return redirect()->to('/');
        }

        $known = $this->parseKnownSites($request->cookie(self::COOKIE_KNOWN_SITES));
        $slugs = array_column($known, 'slug');
        $redirect = redirect()->to('/site/'.$site->slug.'/program/'.$program->slug.'/view');
        if (in_array($site->slug, $slugs, true)) {
            return $redirect;
        }
        return $this->redirectWithKnownSite($request, $site, $redirect);
    }

    private function resolveProgramPrivate(Request $request, SiteShortLink $link): RedirectResponse
    {
        $site = $link->site;
        $program = $link->program;
        if (! $site || ! $program) {
            return redirect()->to('/');
        }

        $known = $this->parseKnownSites($request->cookie(self::COOKIE_KNOWN_SITES));
        $slugs = array_column($known, 'slug');
        $embeddedKey = $link->embedded_key;
        $hasEmbeddedKey = $embeddedKey !== null && $embeddedKey !== '';

        if (! in_array($site->slug, $slugs, true)) {
            // User doesn't have site yet: add site to known_sites so they are not prompted for site key.
            if ($hasEmbeddedKey) {
                return $this->resolveProgramPrivateScannable($request, $link, $program, $site, true);
            }
            $redirect = redirect()->to('/site/'.$site->slug.'?program_key_prompt='.urlencode($program->slug));
            return $this->redirectWithKnownSite($request, $site, $redirect);
        }

        if ($hasEmbeddedKey) {
            return $this->resolveProgramPrivateScannable($request, $link, $program, $site, false);
        }

        return redirect()->to('/site/'.$site->slug.'?program_key_prompt='.urlencode($program->slug));
    }

    /**
     * Add site to known_sites cookie and attach to redirect so user is not prompted for site key.
     */
    private function redirectWithKnownSite(Request $request, Site $site, RedirectResponse $redirect): RedirectResponse
    {
        $known = $this->parseKnownSites($request->cookie(self::COOKIE_KNOWN_SITES));
        $slugs = array_column($known, 'slug');
        if (in_array($site->slug, $slugs, true)) {
            return $redirect;
        }
        $known[] = ['slug' => $site->slug, 'name' => $site->name ?? $site->slug];
        $cookie = cookie(
            self::COOKIE_KNOWN_SITES,
            json_encode($known),
            self::KNOWN_SITES_MAX_AGE_DAYS * 24 * 60,
            '/',
            null,
            $request->secure(),
            false,
            false,
            'lax'
        );

        return $redirect->cookie($cookie);
    }

    private function resolveProgramPrivateScannable(Request $request, SiteShortLink $link, $program, $site, bool $addSiteToKnown): RedirectResponse
    {
        $storedKey = $link->embedded_key;
        $currentKey = $program->settings()->getPublicAccessKey();
        if ($currentKey === null || strcasecmp($storedKey, $currentKey) !== 0) {
            return redirect()->to('/')->with('short_link_invalid', true);
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

        $knownPrograms = $this->parseKnownPrograms($request->cookie(self::COOKIE_KNOWN_PROGRAMS));
        $knownPrograms[] = [
            'site_slug' => $site->slug,
            'program_slug' => $program->slug,
            'program_name' => $program->name,
            'token' => $rawToken,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
        $cookie = cookie(
            self::COOKIE_KNOWN_PROGRAMS,
            json_encode($knownPrograms),
            60 * 24 * 365,
            '/',
            null,
            request()->secure(),
            true,
            false,
            'lax'
        );

        $redirect = redirect()->to('/site/'.$site->slug.'/program/'.$program->slug.'/view')->cookie($cookie);
        if ($addSiteToKnown) {
            $redirect = $this->redirectWithKnownSite($request, $site, $redirect);
        }

        return $redirect;
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

    /**
     * @return list<array<string, mixed>>
     */
    private function parseKnownPrograms(?string $value): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
