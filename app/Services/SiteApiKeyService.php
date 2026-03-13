<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Per central-edge-v2-final §Phase B: site API key generation, hashing, and masking.
 * Raw keys are shown only once on create/regenerate; only api_key_hash is stored.
 */
class SiteApiKeyService
{
    private const PREFIX = 'sk_live_';

    private const MIN_TOTAL_LENGTH = 40;

    /**
     * Generate a cryptographically secure API key with sk_live_ prefix (total length >= 40).
     */
    public function generateKey(): string
    {
        $entropyLength = max(0, self::MIN_TOTAL_LENGTH - strlen(self::PREFIX));
        $key = self::PREFIX . Str::random($entropyLength);

        return strlen($key) >= self::MIN_TOTAL_LENGTH ? $key : self::PREFIX . Str::random(40 - strlen(self::PREFIX));
    }

    /**
     * Assign a new key to the site: generate, hash, save, return raw key once.
     */
    public function assignNewKey(Site $site): string
    {
        $rawKey = $this->generateKey();
        $site->api_key_hash = Hash::make($rawKey);
        $site->save();

        return $rawKey;
    }

    /**
     * Verify raw key against a site's stored hash.
     */
    public function verify(string $rawKey, Site $site): bool
    {
        return Hash::check($rawKey, $site->api_key_hash);
    }

    /**
     * Find site by raw API key (iterates sites and checks hash). Returns null if no match.
     */
    public function findSiteByKey(string $rawKey): ?Site
    {
        $sites = Site::all();
        foreach ($sites as $site) {
            if ($this->verify($rawKey, $site)) {
                return $site;
            }
        }

        return null;
    }

    /**
     * Masked placeholder for GET responses; never reveals the actual key.
     */
    public static function maskedPlaceholder(): string
    {
        return 'sk_live_...****';
    }
}
