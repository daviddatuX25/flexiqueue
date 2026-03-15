<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per public-site plan: validate site key (public_access_key in site.settings).
 * No auth. Throttled 10/min per IP. Returns slug + name on success; 404 on failure (no info leak).
 */
class PublicSiteKeyController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $key = $request->input('key');
        if (! is_string($key) || trim($key) === '') {
            return response()->json(['message' => 'Invalid request.'], 422);
        }

        $keyNormalized = strtoupper(trim($key));
        $site = Site::query()
            ->whereNotNull('settings')
            ->get()
            ->first(fn (Site $s) => strtoupper((string) ($s->settings['public_access_key'] ?? '')) === $keyNormalized);

        if (! $site) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json([
            'slug' => $site->slug,
            'name' => $site->name,
        ]);
    }
}
