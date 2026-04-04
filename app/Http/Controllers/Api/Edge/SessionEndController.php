<?php

namespace App\Http\Controllers\Api\Edge;

use App\Http\Controllers\Controller;
use App\Models\EdgeDevice;
use App\Services\ProgramLockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionEndController extends Controller
{
    public function __construct(private readonly ProgramLockService $lockService) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var EdgeDevice $device */
        $device = $request->attributes->get('edge_device');

        if ($device->assigned_program_id !== null) {
            $this->lockService->unlock($device->assignedProgram);
        }

        $device->update([
            'session_active'  => false,
            'dump_requested'  => false,
        ]);

        return response()->json(['session_active' => false]);
    }
}
