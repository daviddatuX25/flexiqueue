<?php

namespace App\Http\Controllers\Api\Edge;

use App\Http\Controllers\Controller;
use App\Models\EdgeDevice;
use App\Services\ProgramPackageExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssignmentController extends Controller
{
    public function __invoke(
        Request $request,
        ProgramPackageExporter $exporter,
    ): JsonResponse {
        /** @var EdgeDevice $device */
        $device = $request->attributes->get('edge_device');

        if (! $device->assigned_program_id) {
            return response()->json(['assigned' => false, 'program' => null]);
        }

        $device->load(['assignedProgram', 'assignedProgram.site']);

        $packageVersion = $exporter->computePackageVersion(
            $device->assignedProgram,
            $device->assignedProgram->site,
        );

        return response()->json([
            'assigned'                => true,
            'program'                 => [
                'id'   => $device->assignedProgram->id,
                'name' => $device->assignedProgram->name,
            ],
            'sync_mode'               => $device->sync_mode,
            'supervisor_admin_access' => $device->supervisor_admin_access,
            'id_offset'               => $device->id_offset,
            'package_version'          => $packageVersion,
        ]);
    }
}
