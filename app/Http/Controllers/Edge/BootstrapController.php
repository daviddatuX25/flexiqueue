<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Models\EdgeDeviceState;
use App\Services\EdgeModeService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BootstrapController extends Controller
{
    public function show(Request $request)
    {
        $runtime = app(EdgeModeService::class)->runtime();

        return Inertia::render('Edge/Bootstrap', [
            'edgeRuntime' => $runtime,
        ]);
    }

    public function status(Request $request)
    {
        $state = EdgeDeviceState::current();

        $redirect = match (true) {
            $state->is_revoked => '/edge/revoked',
            $state->approval_status === 'pending' => '/edge/awaiting-approval',
            $state->paired_at === null => '/edge/setup',
            $state->active_program_id === null => '/edge/waiting',
            default => '/',
        };

        return response()->json([
            'runtime' => app(EdgeModeService::class)->runtime(),
            'stack_healthy' => true,
            'redirect' => $redirect,
            'state' => [
                'is_revoked' => $state->is_revoked,
                'approval_status' => $state->approval_status,
                'paired' => $state->paired_at !== null,
                'has_program' => $state->active_program_id !== null,
            ],
        ]);
    }
}