<?php

namespace App\Http\Resources;

use App\Models\Session;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Canonical session array shape for API responses.
 * Per docs/REFACTORING-ISSUE-LIST.md Issue 5: single source of truth for session JSON.
 */
class SessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Session $session */
        $session = $this->resource;
        $session->loadMissing(['currentStation', 'serviceTrack']);

        $data = [
            'id' => $session->id,
            'alias' => $session->alias,
            'status' => $session->status,
            'current_step_order' => $session->current_step_order,
            'started_at' => $session->started_at?->toIso8601String(),
            'completed_at' => $session->completed_at?->toIso8601String(),
            'no_show_attempts' => $session->no_show_attempts ?? 0,
            'current_station' => $session->currentStation
                ? ['id' => $session->currentStation->id, 'name' => $session->currentStation->name]
                : null,
            'track' => $session->serviceTrack
                ? ['id' => $session->serviceTrack->id, 'name' => $session->serviceTrack->name]
                : null,
        ];

        return $data;
    }
}
