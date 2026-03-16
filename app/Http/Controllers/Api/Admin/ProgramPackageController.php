<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminActionLog;
use App\Models\Program;
use App\Models\Site;
use App\Models\Station;
use App\Services\ProgramPackageExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Program package export for edge sync. Per docs/final-edge-mode-rush-plann.md [DF-06].
 */
class ProgramPackageController extends Controller
{
    /**
     * Export package (admin session). Logged via AdminActionLog.
     */
    public function show(Request $request, Program $program): JsonResponse
    {
        $siteId = $request->user()->site_id;
        if ($siteId === null) {
            abort(403, 'You must be assigned to a site to export program packages.');
        }

        $site = Site::find($siteId);
        if ($site === null) {
            abort(403, 'Site not found.');
        }

        if ($program->site_id !== $siteId) {
            abort(404, 'Program not found.');
        }

        $package = app(ProgramPackageExporter::class)->export($program, $site);
        AdminActionLog::log(
            $request->user()->id,
            'program_package_exported',
            'Program',
            $program->id,
            ['site_id' => $siteId]
        );

        return response()->json($package);
    }

    /**
     * Export package (site API key). Used by edge Pi; no admin log.
     */
    public function showForSite(Request $request, Program $program): JsonResponse
    {
        $site = $request->attributes->get('site');
        if ($site === null) {
            abort(401, 'Site not bound.');
        }

        if ($program->site_id !== $site->id) {
            abort(404, 'Program not found.');
        }

        $package = app(ProgramPackageExporter::class)->export($program, $site);

        return response()->json($package);
    }

    /**
     * Stream a TTS file for a program (site API key). Filename must be tts/tokens/{id}/*.mp3 or tts/stations/{id}/*.mp3.
     */
    public function streamTtsFile(Request $request, Program $program, string $filename): StreamedResponse
    {
        $site = $request->attributes->get('site');
        if ($site === null) {
            abort(401, 'Site not bound.');
        }

        if ($program->site_id !== $site->id) {
            abort(404, 'Program not found.');
        }

        if (! preg_match('/^tts\/(tokens|stations)\/(\d+)\/.+\.mp3$/', $filename, $matches)) {
            abort(403, 'Invalid TTS file path.');
        }

        $entityType = $matches[1];
        $entityId = (int) $matches[2];

        if ($entityType === 'tokens') {
            $allowed = DB::table('program_token')
                ->where('program_id', $program->id)
                ->where('token_id', $entityId)
                ->exists();
        } else {
            $allowed = Station::where('id', $entityId)->where('program_id', $program->id)->exists();
        }

        if (! $allowed) {
            abort(403, 'TTS file not associated with this program.');
        }

        if (! Storage::disk('local')->exists($filename)) {
            abort(404, 'File not found.');
        }

        return Storage::disk('local')->download($filename);
    }
}
