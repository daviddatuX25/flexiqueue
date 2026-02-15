<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTrackRequest;
use App\Http\Requests\UpdateTrackRequest;
use App\Models\Program;
use App\Models\ServiceTrack;
use App\Models\Session;
use Illuminate\Http\JsonResponse;

/**
 * Per 08-API-SPEC-PHASE1 §5.2: ServiceTrack CRUD. List/create under program; update/delete by track id.
 */
class TrackController extends Controller
{
    /**
     * List tracks for program.
     */
    public function index(Program $program): JsonResponse
    {
        $tracks = $program->serviceTracks()
            ->orderBy('name')
            ->get()
            ->map(fn (ServiceTrack $t) => $this->trackResource($t));

        return response()->json(['tracks' => $tracks]);
    }

    /**
     * Create track. Enforce exactly one default per program (per 04-DATA-MODEL Table 2).
     */
    public function store(StoreTrackRequest $request, Program $program): JsonResponse
    {
        $data = $request->validated();
        if ($data['is_default'] ?? false) {
            $program->serviceTracks()->update(['is_default' => false]);
        }
        $track = $program->serviceTracks()->create($data);

        return response()->json(['track' => $this->trackResource($track)], 201);
    }

    /**
     * Update track. Enforce exactly one default per program.
     */
    public function update(UpdateTrackRequest $request, ServiceTrack $service_track): JsonResponse
    {
        $track = $service_track;
        $data = $request->validated();
        if ($data['is_default'] ?? false) {
            $track->program->serviceTracks()->where('id', '!=', $track->id)->update(['is_default' => false]);
        }
        $track->update($data);

        return response()->json(['track' => $this->trackResource($track->fresh())]);
    }

    /**
     * Delete track. Blocked if active sessions use it (per 08-API-SPEC-PHASE1 §5.2).
     */
    public function destroy(ServiceTrack $service_track): JsonResponse
    {
        $track = $service_track;
        $hasActive = Session::query()
            ->where('track_id', $track->id)
            ->whereIn('status', ['waiting', 'serving'])
            ->exists();

        if ($hasActive) {
            return response()->json(
                ['message' => 'Cannot delete track: active sessions use this track.'],
                400
            );
        }

        $track->delete();

        return response()->json(null, 204);
    }

    private function trackResource(ServiceTrack $track): array
    {
        return [
            'id' => $track->id,
            'program_id' => $track->program_id,
            'name' => $track->name,
            'description' => $track->description,
            'is_default' => $track->is_default,
            'color_code' => $track->color_code,
            'created_at' => $track->created_at?->toIso8601String(),
        ];
    }
}
