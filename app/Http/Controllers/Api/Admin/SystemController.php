<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClearOrphanedTtsRequest;
use App\Http\Requests\ClearStorageRequest;
use App\Services\SystemStorageService;
use Illuminate\Http\JsonResponse;

class SystemController extends Controller
{
    public function __construct(
        private readonly SystemStorageService $storageService
    ) {
    }

    /**
     * GET /api/admin/system/storage
     *
     * Return disk usage snapshot and key app storage categories (TTS, avatars, print images, logs, DB).
     */
    public function storage(): JsonResponse
    {
        return response()->json($this->storageService->getStorageSummary());
    }

    /**
     * POST /api/admin/system/storage/clear
     *
     * Clear a storage category (e.g. tts_audio). Admin-only. Deletes files and nulls DB references.
     */
    public function clearStorage(ClearStorageRequest $request): JsonResponse
    {
        $cleared = $this->storageService->clearCategory($request->validated('category'));

        return response()->json([
            'cleared' => [
                'bytes' => $cleared['bytes'],
                'file_count' => $cleared['file_count'],
            ],
            'message' => 'Storage cleared successfully.',
        ]);
    }

    /**
     * POST /api/admin/system/storage/clear-orphaned-tts
     *
     * Delete only orphan TTS files (not referenced by any token or station). Does not modify DB.
     */
    public function clearOrphanedTts(ClearOrphanedTtsRequest $request): JsonResponse
    {
        $cleared = $this->storageService->clearOrphanedTtsOnly();

        return response()->json([
            'cleared' => [
                'bytes' => $cleared['bytes'],
                'file_count' => $cleared['file_count'],
            ],
            'message' => 'Orphan TTS files removed successfully.',
        ]);
    }
}

