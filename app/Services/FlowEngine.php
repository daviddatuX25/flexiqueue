<?php

namespace App\Services;

use App\Models\Session;
use App\Models\TrackStep;

/**
 * Per 03-FLOW-ENGINE §2: Routing algorithm for session flow.
 * Pure logic — reads data but does NOT mutate anything.
 */
class FlowEngine
{
    /**
     * Calculate the next station for a session in standard flow.
     *
     * @return array{station_id: int, step_order: int}|null Next station + step order, or NULL if flow complete or next station inactive
     */
    public function calculateNextStation(Session $session): ?array
    {
        $nextStep = TrackStep::where('track_id', $session->track_id)
            ->where('step_order', $session->current_step_order + 1)
            ->with('station')
            ->first();

        if (! $nextStep) {
            return null;
        }

        $station = $nextStep->station;
        if (! $station || ! $station->is_active) {
            return null;
        }

        return [
            'station_id' => $nextStep->station_id,
            'step_order' => $nextStep->step_order,
        ];
    }
}
