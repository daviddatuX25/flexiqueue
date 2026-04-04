<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ImportProgramPackageJob;
use App\Models\EdgeDeviceState;
use App\Services\EdgeModeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Edge import trigger and status. Only available in edge mode.
 * Per docs/final-edge-mode-rush-plann.md [DF-10].
 */
class EdgeImportController extends Controller
{
    public function trigger(Request $request): JsonResponse
    {
        if (app(EdgeModeService::class)->isCentral()) {
            return response()->json(['message' => 'This endpoint is only available in edge mode.'], 403);
        }

        $lockPath = storage_path('app/edge_import_running.lock');
        if (file_exists($lockPath) || Storage::disk('local')->exists('edge_import_running.lock')) {
            return response()->json(['status' => 'already_running'], 409);
        }

        $programId = $request->input('program_id');
        $centralUrl = config('app.central_url') ?: env('CENTRAL_URL');
        $apiKey = config('app.central_api_key') ?: env('CENTRAL_API_KEY');

        if ($programId === null || $programId === '' || empty($centralUrl) || empty($apiKey)) {
            return response()->json([
                'message' => 'program_id (in body), CENTRAL_URL and CENTRAL_API_KEY are required in .env (run config:cache on Pi after setting them).',
            ], 422);
        }

        $programId = (int) $programId;
        ImportProgramPackageJob::dispatch($programId, $centralUrl, $apiKey);

        return response()->json(['status' => 'queued']);
    }

    public function status(): JsonResponse
    {
        if (app(EdgeModeService::class)->isCentral()) {
            return response()->json(['message' => 'This endpoint is only available in edge mode.'], 403);
        }

        $lockPath = storage_path('app/edge_import_running.lock');
        $lockExists = file_exists($lockPath) || Storage::disk('local')->exists('edge_import_running.lock');

        if ($lockExists) {
            $data = ['status' => 'running'];
            if (Storage::disk('local')->exists('edge_package_imported.json')) {
                $contents = json_decode(Storage::disk('local')->get('edge_package_imported.json'), true);
                if (is_array($contents)) {
                    $data = array_merge($contents, $data);
                }
            }
            return response()->json($this->appendStateFields($data));
        }

        if (! Storage::disk('local')->exists('edge_package_imported.json')) {
            return response()->json($this->appendStateFields(['status' => 'never_synced']));
        }

        $contents = Storage::disk('local')->get('edge_package_imported.json');
        $data = json_decode($contents, true);

        return response()->json($this->appendStateFields(is_array($data) ? $data : ['status' => 'unknown']));
    }

    /** Append package_stale, update_available, and session_active from EdgeDeviceState to the response. */
    private function appendStateFields(array $data): array
    {
        $state = EdgeDeviceState::current();

        return array_merge($data, [
            'package_stale'    => $state->package_stale ?? false,
            'update_available' => $state->update_available ?? false,
            'session_active'   => $state->session_active ?? false,
        ]);
    }
}
