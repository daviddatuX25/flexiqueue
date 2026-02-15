<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReorderTrackStepsRequest;
use App\Http\Requests\StoreTrackStepRequest;
use App\Http\Requests\UpdateTrackStepRequest;
use App\Models\Session;
use App\Models\ServiceTrack;
use App\Models\TrackStep;
use Illuminate\Http\JsonResponse;

/**
 * Per 08-API-SPEC-PHASE1 §5.4: Track Step CRUD + reorder. Steps ordered by step_order; contiguous (1,2,3...).
 */
class StepController extends Controller
{
    /**
     * List steps for track (ordered).
     */
    public function index(ServiceTrack $track): JsonResponse
    {
        $steps = $track->trackSteps()->get()->map(fn (TrackStep $s) => $this->stepResource($s));

        return response()->json(['steps' => $steps]);
    }

    /**
     * Add step. If step_order omitted, append at end. Enforces contiguous step_order after.
     */
    public function store(StoreTrackStepRequest $request, ServiceTrack $track): JsonResponse
    {
        $data = $request->validated();
        if (! isset($data['step_order'])) {
            $max = $track->trackSteps()->max('step_order') ?? 0;
            $data['step_order'] = $max + 1;
        }
        $step = $track->trackSteps()->create($data);
        $this->normalizeStepOrder($track);

        return response()->json(['step' => $this->stepResource($step->fresh())], 201);
    }

    /**
     * Update step. Re-normalize step_order if changed.
     */
    public function update(UpdateTrackStepRequest $request, TrackStep $step): JsonResponse
    {
        $step->update($request->validated());
        $this->normalizeStepOrder($step->serviceTrack);

        return response()->json(['step' => $this->stepResource($step->fresh())]);
    }

    /**
     * Delete step and reorder remaining to contiguous 1,2,3...
     */
    public function destroy(TrackStep $step): JsonResponse
    {
        $track = $step->serviceTrack;
        $step->delete();
        $this->normalizeStepOrder($track);

        return response()->json(null, 204);
    }

    /**
     * Reorder by step_ids array; new step_order = index+1.
     * Use temporary offset to avoid unique (track_id, step_order) constraint during update.
     *
     * If migrate_sessions=true and track has active sessions, remap each session's
     * current_step_order to the new step that has their current_station_id.
     */
    public function reorder(ReorderTrackStepsRequest $request, ServiceTrack $track): JsonResponse
    {
        $validated = $request->validated();
        $stepIds = $validated['step_ids'];
        $migrateSessions = (bool) ($validated['migrate_sessions'] ?? false);

        $offset = 10000;
        foreach ($track->trackSteps as $step) {
            $step->update(['step_order' => $offset + $step->id]);
        }
        foreach ($stepIds as $i => $id) {
            TrackStep::where('id', $id)->where('track_id', $track->id)->update(['step_order' => $i + 1]);
        }

        if ($migrateSessions) {
            $this->migrateSessionsToNewOrder($track);
        }

        $steps = $track->trackSteps()->get()->map(fn (TrackStep $s) => $this->stepResource($s));

        return response()->json(['steps' => $steps]);
    }

    /**
     * Remap active sessions' current_step_order to match new step order.
     * Each session keeps current_station_id; we update step_order to the step that now has that station.
     */
    private function migrateSessionsToNewOrder(ServiceTrack $track): void
    {
        $stepsByStation = $track->trackSteps()->get()->keyBy('station_id');

        Session::active()
            ->where('track_id', $track->id)
            ->each(function (Session $session) use ($stepsByStation): void {
                $step = $stepsByStation->get($session->current_station_id);
                if ($step) {
                    $session->update(['current_step_order' => $step->step_order]);
                }
            });
    }

    private function stepResource(TrackStep $step): array
    {
        $step->load('station');

        return [
            'id' => $step->id,
            'track_id' => $step->track_id,
            'station_id' => $step->station_id,
            'station_name' => $step->station?->name,
            'step_order' => $step->step_order,
            'is_required' => $step->is_required,
            'estimated_minutes' => $step->estimated_minutes,
            'created_at' => $step->created_at?->toIso8601String(),
        ];
    }

    /**
     * Make step_order contiguous (1, 2, 3...) for the track.
     */
    private function normalizeStepOrder(ServiceTrack $track): void
    {
        $steps = $track->trackSteps()->orderBy('step_order')->get();
        foreach ($steps as $i => $s) {
            $s->update(['step_order' => $i + 1]);
        }
    }
}
