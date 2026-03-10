<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SetStationProcessesRequest;
use App\Http\Requests\StoreStationRequest;
use App\Http\Requests\UpdateStationRequest;
use App\Jobs\GenerateStationTtsJob;
use App\Models\Program;
use App\Models\Station;
use App\Models\TrackStep;
use App\Support\QueueWorkerIdleCheck;
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

        $tts = $data['tts'] ?? null;
        unset($data['tts']);

        if ($tts !== null) {
            $languages = $tts['languages'] ?? [];
            $data['settings'] = array_merge(
                $data['settings'] ?? [],
                [
                    'tts' => [
                        'languages' => $languages,
                    ],
                ]
            );
        }

        $data = array_merge($data, ['is_active' => true]);
        $station = $program->stations()->create($data);

        if (! empty($processIds)) {
            $station->processes()->sync($processIds);
        }

        $station = $station->fresh();
        $program = $station->program;
        $autoGenerateStationTts = $program->settings['tts']['auto_generate_station_tts'] ?? true;
        if ($autoGenerateStationTts) {
            $workerIdle = QueueWorkerIdleCheck::appearsIdle();
            if ($workerIdle && config('tts.allow_sync_when_queue_unavailable', false)) {
                GenerateStationTtsJob::dispatchSync($station);
            } else {
                GenerateStationTtsJob::dispatch($station);
            }
        }

        return response()->json(['station' => $this->stationResource($station)], 201);
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

        $tts = $data['tts'] ?? null;
        unset($data['tts']);

        $station->update($data);

        $requiresRegeneration = false;
        if ($tts !== null) {
            $settings = $station->settings ?? [];
            $languagesInput = $tts['languages'] ?? [];
            $existingLanguages = $settings['tts']['languages'] ?? [];

            foreach ($existingLanguages as $config) {
                if (is_array($config) && (! empty($config['audio_path']) || ($config['status'] ?? '') === 'ready')) {
                    $requiresRegeneration = true;
                    break;
                }
            }

            foreach (['en', 'fil', 'ilo'] as $lang) {
                if (! isset($languagesInput[$lang]) || ! is_array($languagesInput[$lang])) {
                    continue;
                }

                $input = $languagesInput[$lang];
                $config = $existingLanguages[$lang] ?? [];

                if (array_key_exists('voice_id', $input)) {
                    $config['voice_id'] = $input['voice_id'] !== '' ? $input['voice_id'] : null;
                }
                if (array_key_exists('rate', $input) && $input['rate'] !== null && $input['rate'] !== '') {
                    $config['rate'] = (float) $input['rate'];
                }
                if (array_key_exists('station_phrase', $input)) {
                    $value = $input['station_phrase'];
                    $config['station_phrase'] = is_string($value) && trim($value) !== '' ? trim($value) : null;
                }

                $existingLanguages[$lang] = $config;
            }

            $settings['tts']['languages'] = $existingLanguages;
            $station->settings = $settings;
            $station->save();
        }

        if ($processIds !== null) {
            $station->processes()->sync($processIds);
        }

        $station = $station->fresh();
        $payload = ['station' => $this->stationResource($station)];
        if ($requiresRegeneration) {
            $payload['requires_regeneration'] = true;
        }

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

    /**
     * Regenerate TTS for this station (connector + station phrase). Called after user confirms regeneration prompt.
     */
    public function regenerateTts(Station $station): JsonResponse
    {
        $station = $station->fresh();
        $workerIdle = QueueWorkerIdleCheck::appearsIdle();
        if ($workerIdle && config('tts.allow_sync_when_queue_unavailable', false)) {
            GenerateStationTtsJob::dispatchSync($station);
        } else {
            GenerateStationTtsJob::dispatch($station);
        }

        return response()->json(['message' => 'Station TTS regeneration started.']);
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
            'tts' => [
                'languages' => ($station->settings['tts']['languages'] ?? []),
            ],
        ];
    }
}
