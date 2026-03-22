<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\StationPageController;
use App\Models\Program;
use App\Services\EdgeModeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON payload for StaffTriageBindPanel / footer QR triage modal (Phase 1 device refactor).
 */
class StaffTriageBindContextController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $program = StationPageController::resolveProgramForStaffWithoutStation($request);
        if (! $program instanceof Program) {
            return response()->json(['message' => 'No active program.'], 422);
        }

        $program->load('serviceTracks:id,program_id,name,color_code,is_default');
        $programSettings = $program->settings();
        $effectiveBindingMode = app(EdgeModeService::class)
            ->getEffectiveBindingMode($programSettings->getIdentityBindingMode());

        $payload = [
            'id' => $program->id,
            'name' => $program->name,
            'is_active' => $program->is_active,
            'is_paused' => $program->is_paused,
            'identity_binding_mode' => $effectiveBindingMode,
            /** When false, client category comes from identity binding flow; when true, staff must pick PWD / Regular / etc. for queue priority. */
            'show_staff_client_category' => $effectiveBindingMode !== 'required',
            'allow_unverified_entry' => $programSettings->getAllowUnverifiedEntry(),
            'tracks' => $program->serviceTracks->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'color_code' => $t->color_code,
                'is_default' => $t->is_default,
            ])->values()->all(),
        ];

        return response()->json($payload);
    }
}
