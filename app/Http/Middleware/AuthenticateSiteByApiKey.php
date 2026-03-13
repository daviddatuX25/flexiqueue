<?php

namespace App\Http\Middleware;

use App\Services\SiteApiKeyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per central-edge-v2-final §Phase B: authenticate sync/bridge requests by site API key.
 * Reads Authorization: Bearer {raw_key}, verifies against sites.api_key_hash, binds site to request.
 * Returns 401 on missing, malformed, or invalid key. Does not grant admin/user identity.
 */
class AuthenticateSiteByApiKey
{
    public function __construct(
        private SiteApiKeyService $siteApiKeyService
    ) {}

    /**
     * @param  \Closure(Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $auth = $request->header('Authorization');
        if (! is_string($auth) || $auth === '') {
            return response()->json(['message' => 'Missing or invalid Authorization header.'], 401);
        }

        if (! str_starts_with($auth, 'Bearer ')) {
            return response()->json(['message' => 'Authorization must use Bearer scheme.'], 401);
        }

        $rawKey = trim(substr($auth, 7));
        if ($rawKey === '') {
            return response()->json(['message' => 'Missing or invalid Authorization header.'], 401);
        }

        $site = $this->siteApiKeyService->findSiteByKey($rawKey);
        if ($site === null) {
            return response()->json(['message' => 'Invalid or revoked API key.'], 401);
        }

        $request->attributes->set('site', $site);
        $request->attributes->set('site_id', $site->id);

        return $next($request);
    }
}
