<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClearOrphanedTtsRequest;
use App\Http\Requests\ClearStorageRequest;
use App\Services\SystemStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
     * Per site-scoping-migration-spec §2: TTS stats scoped by user's site when site admin.
     */
    public function storage(Request $request): JsonResponse
    {
        $siteId = $request->user()->isSuperAdmin() ? null : $request->user()->site_id;

        return response()->json($this->storageService->getStorageSummary($siteId));
    }

    /**
     * POST /api/admin/system/storage/clear
     *
     * Clear a storage category (e.g. tts_audio). Admin-only. Deletes files and nulls DB references.
     * Per site-scoping-migration-spec §2: for tts_audio, scope by user's site when site admin.
     */
    public function clearStorage(ClearStorageRequest $request): JsonResponse
    {
        $siteId = $request->user()->isSuperAdmin() ? null : $request->user()->site_id;
        $cleared = $this->storageService->clearCategory($request->validated('category'), $siteId);

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
     * Per site-scoping-migration-spec §2: when site admin, only orphans for that site's token refs.
     */
    public function clearOrphanedTts(ClearOrphanedTtsRequest $request): JsonResponse
    {
        $siteId = $request->user()->isSuperAdmin() ? null : $request->user()->site_id;
        $cleared = $this->storageService->clearOrphanedTtsOnly($siteId);

        return response()->json([
            'cleared' => [
                'bytes' => $cleared['bytes'],
                'file_count' => $cleared['file_count'],
            ],
            'message' => 'Orphan TTS files removed successfully.',
        ]);
    }
}

