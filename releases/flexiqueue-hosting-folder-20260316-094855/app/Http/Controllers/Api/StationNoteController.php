<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateStationNoteRequest;
use App\Models\Station;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Station notes API. Any staff with access to the station can read/write.
 */
class StationNoteController extends Controller
{
    /**
     * GET /api/stations/{station}/notes — Get current note for station.
     */
    public function show(Station $station): JsonResponse
    {
        Gate::authorize('view', $station);

        $note = $station->note;
        $author = $note?->updatedByUser;

        return response()->json([
            'note' => $note ? [
                'message' => $note->message,
                'author_name' => $author?->name,
                'updated_at' => $note->updated_at?->toIso8601String(),
            ] : null,
        ]);
    }

    /**
     * PUT /api/stations/{station}/notes — Set or update note.
     */
    public function update(UpdateStationNoteRequest $request, Station $station): JsonResponse
    {
        Gate::authorize('view', $station);

        $message = $request->validated('message') ?? '';
        $user = $request->user();

        $note = $station->note()->updateOrCreate(
            ['station_id' => $station->id],
            [
                'message' => $message === '' ? null : $message,
                'updated_by' => $user->id,
            ]
        );

        \App\Events\StationNoteUpdated::dispatch($station, $note->message ?? '', $user->name, $note->updated_at);

        return response()->json([
            'note' => [
                'message' => $note->message,
                'author_name' => $user->name,
                'updated_at' => $note->updated_at?->toIso8601String(),
            ],
            'message' => 'Note updated.',
        ]);
    }
}
