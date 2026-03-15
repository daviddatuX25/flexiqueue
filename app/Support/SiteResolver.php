<?php

namespace App\Support;

use App\Models\Site;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves the default site for the deployment (public display/triage).
 * Uses sites.is_default when set; otherwise falls back to Site::first().
 * Cached 60 seconds; clear via clearDefaultCache() when default site changes.
 */
class SiteResolver
{
    private const CACHE_KEY = 'default_site';

    /**
     * Return the default site. Cached 60 seconds.
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException when no site exists
     */
    public static function default(): Site
    {
        return Cache::remember(self::CACHE_KEY, 60, function () {
            $site = Site::where('is_default', true)->first() ?? Site::orderBy('id')->first();
            if ($site === null) {
                throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)->setModel(Site::class);
            }
            return $site;
        });
    }

    /**
     * Return the default site or null when no site exists. Use on public routes to return 503 instead of 500.
     * Cached 60 seconds.
     */
    public static function defaultIfExists(): ?Site
    {
        try {
            return self::default();
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return null;
        }
    }

    /**
     * Clear the default-site cache. Call after changing which site is default.
     */
    public static function clearDefaultCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
