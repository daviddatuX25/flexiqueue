<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateTtsPlatformBudgetRequest;
use App\Services\Tts\TtsPlatformBudgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TtsPlatformBudgetController extends Controller
{
    public function __construct(
        private readonly TtsPlatformBudgetService $platformBudgetService
    ) {}

    public function show(Request $request): JsonResponse
    {
        if (! $request->user()->isSuperAdmin()) {
            abort(403);
        }

        return response()->json($this->platformBudgetService->buildDashboardPayload());
    }

    public function update(UpdateTtsPlatformBudgetRequest $request): JsonResponse
    {
        $global = $request->only([
            'global_enabled',
            'period',
            'mode',
            'char_limit',
            'block_on_limit',
            'warning_threshold_pct',
        ]);

        /** @var array<int|string, mixed> $weightsInput */
        $weightsInput = $request->input('weights', []);
        $weightsBySiteId = [];
        if (is_array($weightsInput)) {
            foreach ($weightsInput as $siteId => $w) {
                $weightsBySiteId[(int) $siteId] = (int) $w;
            }
        }

        $this->platformBudgetService->updateSettings($global, $weightsBySiteId);

        return response()->json($this->platformBudgetService->buildDashboardPayload());
    }
}
