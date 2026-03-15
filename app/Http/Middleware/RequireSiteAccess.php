<?php

namespace App\Http\Middleware;

use App\Models\Site;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per public-site plan: for routes under site/{slug}/..., require that the request
 * has this site's slug in the known_sites cookie. Otherwise redirect to / (do not 404).
 */
class RequireSiteAccess
{
    private const COOKIE_NAME = 'known_sites';

    public function handle(Request $request, Closure $next): Response
    {
        $site = $request->route('site');
        if (! $site instanceof Site) {
            return $next($request);
        }

        $known = $this->parseKnownSites($request->cookie(self::COOKIE_NAME));
        $slugs = array_column($known, 'slug');
        if (! in_array($site->slug, $slugs, true)) {
            return redirect()->to('/');
        }

        return $next($request);
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
}
