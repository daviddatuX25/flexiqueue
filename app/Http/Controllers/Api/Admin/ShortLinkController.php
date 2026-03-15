<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Models\Site;
use App\Models\SiteShortLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Per addition-to-public-site-plan Part 6.1 + Addition A.4: generate short links for QR codes.
 */
class ShortLinkController extends Controller
{
    /**
     * POST /api/admin/sites/{site}/generate-qr — site_entry short link.
     */
    public function storeForSite(Request $request, Site $site): JsonResponse
    {
        $this->ensureSiteAccess($request, $site);

        $existing = SiteShortLink::query()
            ->where('site_id', $site->id)
            ->where('type', SiteShortLink::TYPE_SITE_ENTRY)
            ->first();

        if ($existing) {
            return response()->json([
                'code' => $existing->code,
                'url' => $this->shortUrl($existing->code),
            ]);
        }

        $link = $this->createLink(SiteShortLink::TYPE_SITE_ENTRY, $site->id, null, null);

        return response()->json([
            'code' => $link->code,
            'url' => $this->shortUrl($link->code),
        ], 201);
    }

    /**
     * POST /api/admin/programs/{program}/generate-qr — body: type = public | private_prompt | private_scannable.
     */
    public function storeForProgram(Request $request, Program $program): JsonResponse
    {
        $this->ensureProgramAccess($request, $program);

        $type = $request->input('type', 'public');
        if (! in_array($type, ['public', 'private_prompt', 'private_scannable'], true)) {
            return response()->json(['message' => 'Invalid type.'], 422);
        }

        $linkType = $type === 'public' ? SiteShortLink::TYPE_PROGRAM_PUBLIC : SiteShortLink::TYPE_PROGRAM_PRIVATE;
        $embeddedKey = null;
        if ($type === 'private_scannable') {
            $key = $program->settings()->getPublicAccessKey();
            if ($key === null || $key === '') {
                return response()->json(['message' => 'Program has no key set.'], 422);
            }
            $embeddedKey = $key;
        }

        $existing = SiteShortLink::query()
            ->where('program_id', $program->id)
            ->where('type', $linkType)
            ->when($type === 'private_scannable', fn ($q) => $q->whereNotNull('embedded_key'))
            ->when($type === 'private_prompt', fn ($q) => $q->whereNull('embedded_key'))
            ->first();

        if ($existing) {
            return response()->json([
                'code' => $existing->code,
                'url' => $this->shortUrl($existing->code),
            ]);
        }

        $link = $this->createLink($linkType, $program->site_id, $program->id, $embeddedKey);

        return response()->json([
            'code' => $link->code,
            'url' => $this->shortUrl($link->code),
        ], 201);
    }

    private function shortUrl(string $code): string
    {
        return rtrim(config('app.url'), '/').'/go/'.$code;
    }

    private function createLink(string $type, ?int $siteId, ?int $programId, ?string $embeddedKey): SiteShortLink
    {
        $code = $this->uniqueCode();
        return SiteShortLink::create([
            'code' => $code,
            'type' => $type,
            'site_id' => $siteId,
            'program_id' => $programId,
            'embedded_key' => $embeddedKey,
        ]);
    }

    private function uniqueCode(): string
    {
        $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        for ($i = 0; $i < 20; $i++) {
            $code = Str::random(8);
            if (! SiteShortLink::query()->where('code', $code)->exists()) {
                return $code;
            }
        }
        return Str::random(12);
    }

    private function ensureSiteAccess(Request $request, Site $site): void
    {
        $user = $request->user();
        if ($user->site_id !== null && $user->site_id !== $site->id) {
            abort(404);
        }
    }

    private function ensureProgramAccess(Request $request, Program $program): void
    {
        $user = $request->user();
        if ($user->site_id !== null && $user->site_id !== $program->site_id) {
            abort(404);
        }
    }
}
