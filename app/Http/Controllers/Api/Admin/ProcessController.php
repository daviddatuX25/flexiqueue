<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProcessRequest;
use App\Http\Requests\UpdateProcessRequest;
use App\Models\Process;
use App\Models\Program;
use Illuminate\Http\JsonResponse;

/**
 * Per PROCESS-STATION-REFACTOR §9.1: Process CRUD. Auth: role:admin.
 * Per ISSUES-ELABORATION §19: update and delete (block if in use).
 */
class ProcessController extends Controller
{
    /**
     * List processes for program.
     */
    public function index(Program $program): JsonResponse
    {
        $processes = $program->processes()->orderBy('name')->get()->map(fn (Process $p) => $this->processResource($p));

        return response()->json(['processes' => $processes]);
    }

    /**
     * Create process for program.
     */
    public function store(StoreProcessRequest $request, Program $program): JsonResponse
    {
        $data = $request->validated();
        $process = $program->processes()->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'expected_time_seconds' => $data['expected_time_seconds'] ?? null,
        ]);

        return response()->json(['process' => $this->processResource($process)], 201);
    }

    /**
     * Update process. Per ISSUES-ELABORATION §19: name and description.
     */
    public function update(UpdateProcessRequest $request, Program $program, Process $process): JsonResponse
    {
        $this->ensureProcessBelongsToProgram($program, $process);

        $data = $request->validated();
        $process->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'expected_time_seconds' => $data['expected_time_seconds'] ?? null,
        ]);

        return response()->json(['process' => $this->processResource($process->fresh())]);
    }

    /**
     * Delete process. Per ISSUES-ELABORATION §19: return 422 if in use by stations or track steps.
     */
    public function destroy(Program $program, Process $process): JsonResponse
    {
        $this->ensureProcessBelongsToProgram($program, $process);

        if ($process->stations()->exists()) {
            return response()->json([
                'message' => 'Process is in use by one or more stations. Remove it from station assignments first.',
            ], 422);
        }

        if ($process->trackSteps()->exists()) {
            return response()->json([
                'message' => 'Process is in use by one or more track steps. Remove it from track steps first.',
            ], 422);
        }

        $process->delete();

        return response()->json(null, 204);
    }

    private function ensureProcessBelongsToProgram(Program $program, Process $process): void
    {
        if ($process->program_id !== $program->id) {
            abort(404);
        }
    }

    private function processResource(Process $process): array
    {
        return [
            'id' => $process->id,
            'program_id' => $process->program_id,
            'name' => $process->name,
            'description' => $process->description,
            'expected_time_seconds' => $process->expected_time_seconds,
            'created_at' => $process->created_at?->toIso8601String(),
        ];
    }
}
