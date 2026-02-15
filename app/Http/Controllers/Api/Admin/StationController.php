<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStationRequest;
use App\Http\Requests\UpdateStationRequest;
use App\Models\Program;
use App\Models\Station;
use App\Models\TrackStep;
use Illuminate\Http\JsonResponse;

/**
 * Per 08-API-SPEC-PHASE1 §5.3: Station CRUD. List/create under program; update/delete by station id.
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
        $data = array_merge($request->validated(), ['is_active' => true]);
        $station = $program->stations()->create($data);

        return response()->json(['station' => $this->stationResource($station)], 201);
    }

    /**
     * Update station.
     */
    public function update(UpdateStationRequest $request, Station $station): JsonResponse
    {
        $station->update($request->validated());

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
        return [
            'id' => $station->id,
            'program_id' => $station->program_id,
            'name' => $station->name,
            'capacity' => $station->capacity,
            'client_capacity' => $station->client_capacity ?? 1,
            'priority_first_override' => $station->priority_first_override,
            'is_active' => $station->is_active,
            'created_at' => $station->created_at?->toIso8601String(),
        ];
    }
}
