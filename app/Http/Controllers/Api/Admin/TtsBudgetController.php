<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Tts\TtsPeriodKey;
use App\Services\Tts\TtsPlatformBudgetService;
use App\Services\Tts\TtsUsageRollupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Site-scoped TTS budget status for admin UI. Per phase4 governance.
 * No credential access; policy visibility only.
 */
class TtsBudgetController extends Controller
{
    /**
     * GET /api/admin/tts/budgets — all sites' budget status (super_admin only).
     */
    public function indexAll(Request $request): JsonResponse
    {
        if (! $request->user()->isSuperAdmin()) {
            abort(403, 'Only super admins can view cross-site budgets.');
        }

        $sites = Site::query()->orderBy('name')->get();
        $budgets = [];

        foreach ($sites as $site) {
            $policy = $site->getTtsBudgetPolicy();
            $item = [
                'site_id' => $site->id,
                'site_name' => $site->name,
                'slug' => $site->slug,
                'policy' => [
                    'enabled' => $policy->enabled,
                    'limit' => $policy->limit,
                    'period' => $policy->period,
                    'block_on_limit' => $policy->blockOnLimit,
                ],
            ];

            if ($policy->isEnforced()) {
                $periodKey = TtsPeriodKey::forNow($policy->period);
                $charsUsed = $this->rollupService->getCharsUsed($site->id, $periodKey);
                $item['usage'] = ['chars_used' => $charsUsed, 'period_key' => $periodKey];
                $item['remaining'] = max(0, $policy->limit - $charsUsed);
                $item['at_limit'] = $policy->blockOnLimit && $charsUsed >= $policy->limit;
            } else {
                $item['usage'] = null;
                $item['remaining'] = null;
                $item['at_limit'] = false;
            }

            $budgets[] = $item;
        }

        return response()->json(['budgets' => $budgets]);
    }

    public function __construct(
        private readonly TtsUsageRollupService $rollupService,
        private readonly TtsPlatformBudgetService $platformBudgetService
    ) {}

    /**
     * GET /api/admin/tts/budget — budget for the current user's site.
     * Site admin gets their site; super_admin gets 404 (use /sites/{site}/tts-budget for a specific site).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->site_id === null) {
            return response()->json(['error' => 'No site assigned. Use /api/admin/sites/{site}/tts-budget for a specific site.'], 404);
        }

        $site = Site::find($user->site_id);
        if (! $site) {
            return response()->json(['error' => 'Site not found.'], 404);
        }

        return $this->show($request, $site);
    }

    /**
     * GET /api/admin/sites/{site}/tts-budget
     * Returns current period usage, limit, remaining, and policy for the site.
     */
    public function show(Request $request, Site $site): JsonResponse
    {
        $this->ensureSiteAccess($request, $site);

        $policy = $site->getTtsBudgetPolicy();
        $platformSettings = $this->platformBudgetService->getSettingsRecord();
        $globalEnabled = (bool) $platformSettings->global_enabled;

        $response = [
            'policy' => [
                'enabled' => $policy->enabled,
                'mode' => $policy->mode,
                'period' => $policy->period,
                'limit' => $policy->limit,
                'warning_threshold_pct' => $policy->warningThresholdPct,
                'block_on_limit' => $policy->blockOnLimit,
            ],
            'platform_global_budget_enabled' => $globalEnabled,
        ];

        if ($globalEnabled && $this->platformBudgetService->isGlobalEnforced()) {
            $periodKey = $this->platformBudgetService->periodKeyForPlatform();
            $effective = $this->platformBudgetService->getEffectiveLimitForSite($site->id) ?? 0;
            $charsUsed = $this->rollupService->getCharsUsed($site->id, $periodKey);
            $platformTotal = $this->platformBudgetService->getTotalCharsUsedForPlatformPeriod();
            $remaining = max(0, $effective - $charsUsed);
            $atLimit = $platformSettings->block_on_limit && $effective > 0 && $charsUsed >= $effective;

            $response['global_monitoring'] = [
                'period_key' => $periodKey,
                'effective_char_limit' => $effective,
                'chars_used' => $charsUsed,
                'remaining' => $remaining,
                'at_limit' => $atLimit,
                'platform_char_limit' => $platformSettings->char_limit,
                'platform_chars_used_total' => $platformTotal,
                'warning_threshold_pct' => (int) $platformSettings->warning_threshold_pct,
            ];
            $response['usage'] = [
                'chars_used' => $charsUsed,
                'period_key' => $periodKey,
            ];
            $response['remaining'] = $remaining;
            $response['at_limit'] = $atLimit;
            $response['period_key'] = $periodKey;

            return response()->json($response);
        }

        if ($globalEnabled) {
            $response['global_monitoring'] = [
                'message' => 'Platform global budgeting is enabled. Set the shared pool and per-site weights in Configuration (super admin).',
            ];
        }

        if (! $policy->isEnforced()) {
            $response['usage'] = null;
            $response['remaining'] = null;
            $response['at_limit'] = false;
            $response['period_key'] = null;

            return response()->json($response);
        }

        $periodKey = TtsPeriodKey::forNow($policy->period);
        $charsUsed = $this->rollupService->getCharsUsed($site->id, $periodKey);

        $remaining = max(0, $policy->limit - $charsUsed);
        $atLimit = $policy->blockOnLimit && $charsUsed >= $policy->limit;

        $response['usage'] = [
            'chars_used' => $charsUsed,
            'period_key' => $periodKey,
        ];
        $response['remaining'] = $remaining;
        $response['at_limit'] = $atLimit;
        $response['period_key'] = $periodKey;

        return response()->json($response);
    }

    private function ensureSiteAccess(Request $request, Site $site): void
    {
        if ($request->user()->isSuperAdmin()) {
            return;
        }
        if ($request->user()->site_id !== $site->id) {
            abort(404);
        }
    }
}
