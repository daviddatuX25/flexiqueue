<?php

namespace App\Http\Controllers\Api\Edge;

use App\Http\Controllers\Controller;
use App\Models\EdgeDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionStartController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var EdgeDevice $device */
        $device = $request->attributes->get('edge_device');

        if ($device->assigned_program_id === null) {
            return response()->json([
                'error' => 'No program assigned to this device.',
            ], 422);
        }

        $device->update(['session_active' => true]);

        return response()->json([
            'session_active'      => true,
            'assigned_program_id' => $device->assigned_program_id,
        ]);
    }
}
