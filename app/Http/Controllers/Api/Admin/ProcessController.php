<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProcessRequest;
use App\Models\Process;
use App\Models\Program;
use Illuminate\Http\JsonResponse;

/**
 * Per PROCESS-STATION-REFACTOR §9.1: Process CRUD. Auth: role:admin.
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
        ]);

        return response()->json(['process' => $this->processResource($process)], 201);
    }

    private function processResource(Process $process): array
    {
        return [
            'id' => $process->id,
            'program_id' => $process->program_id,
            'name' => $process->name,
            'description' => $process->description,
            'created_at' => $process->created_at?->toIso8601String(),
        ];
    }
}
