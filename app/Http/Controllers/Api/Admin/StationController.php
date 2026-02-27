<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SetStationProcessesRequest;
use App\Http\Requests\StoreStationRequest;
use App\Http\Requests\UpdateStationRequest;
use App\Models\Program;
use App\Models\Station;
use App\Models\TrackStep;
use Illuminate\Http\JsonResponse;

/**
 * Per 08-API-SPEC-PHASE1 §5.3: Station CRUD. Per PROCESS-STATION-REFACTOR §9.2: process assignment.
 */
class StationController extends Controller
{
    /**
     * List stations for program.
     */
    public function index(Program $program): JsonResponse
    {
        $stations = $program->stations()
            ->orderBy('name')
            ->get()
            ->map(fn (Station $s) => $this->stationResource($s));

        return response()->json(['stations' => $stations]);
    }

    /**
     * Create station.
     */
    public function store(StoreStationRequest $request, Program $program): JsonResponse
    {
        $data = $request->validated();
        $processIds = $data['process_ids'] ?? [];
        unset($data['process_ids']);

        $data = array_merge($data, ['is_active' => true]);
        $station = $program->stations()->create($data);

        if (! empty($processIds)) {
            $station->processes()->sync($processIds);
        }

        return response()->json(['station' => $this->stationResource($station->fresh())], 201);
    }

    /**
     * Update station.
     * Per ISSUES-ELABORATION §17: when deactivating, include warning if station's processes are used in track steps.
     */
    public function update(UpdateStationRequest $request, Station $station): JsonResponse
    {
        $data = $request->validated();
        $processIds = $data['process_ids'] ?? null;
        unset($data['process_ids']);

        $station->update($data);

        if ($processIds !== null) {
            $station->processes()->sync($processIds);
        }

        $station = $station->fresh();
        $payload = ['station' => $this->stationResource($station)];

        if (($data['is_active'] ?? true) === false) {
            $stationProcessIds = $station->processes()->pluck('processes.id')->all();
            if (! empty($stationProcessIds)) {
                $programTrackIds = $station->program->serviceTracks()->pluck('id')->all();
                $usedInTracks = TrackStep::query()
                    ->whereIn('process_id', $stationProcessIds)
                    ->whereIn('track_id', $programTrackIds)
                    ->exists();
                if ($usedInTracks) {
                    $payload['warning'] = "This station's processes are used in track steps. Deactivating may prevent tracks from completing.";
                }
            }
        }

        return response()->json($payload);
    }

    /**
     * List processes assigned to station. Per PROCESS-STATION-REFACTOR §9.2.
     */
    public function listProcesses(Program $program, Station $station): JsonResponse
    {
        if ($station->program_id !== $program->id) {
            return response()->json(['message' => 'Station does not belong to program.'], 404);
        }

        $processIds = $station->processes()->pluck('processes.id')->all();

        return response()->json(['process_ids' => $processIds]);
    }

    /**
     * Set processes for station. Per PROCESS-STATION-REFACTOR §9.2. Must have ≥1.
     */
    public function setProcesses(SetStationProcessesRequest $request, Program $program, Station $station): JsonResponse
    {
        if ($station->program_id !== $program->id) {
            return response()->json(['message' => 'Station does not belong to program.'], 404);
        }

        $station->processes()->sync($request->validated()['process_ids']);

        return response()->json(['station' => $this->stationResource($station->fresh())]);
    }

    /**
     * Delete station. Blocked if referenced by track steps (per 08-API-SPEC-PHASE1 §5.3).
     */
    public function destroy(Station $station): JsonResponse
    {
        $referencedBySteps = TrackStep::query()
            ->where('station_id', $station->id)
            ->exists();

        if ($referencedBySteps) {
            return response()->json(
                ['message' => 'Cannot delete station: it is used in track steps.'],
                400
            );
        }

        $station->delete();

        return response()->json(null, 204);
    }

    private function stationResource(Station $station): array
    {
        $station->loadMissing('processes');

        return [
            'id' => $station->id,
            'program_id' => $station->program_id,
            'name' => $station->name,
            'capacity' => $station->capacity,
            'client_capacity' => $station->client_capacity ?? 1,
            'priority_first_override' => $station->priority_first_override,
            'is_active' => $station->is_active,
            'created_at' => $station->created_at?->toIso8601String(),
            'process_ids' => $station->processes->pluck('id')->values()->all(),
        ];
    }
}
