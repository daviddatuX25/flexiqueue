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
     * Per TRACK-OVERRIDES-REFACTOR: when session.override_steps is set, use that path instead of track_steps.
     *
     * @return array{station_id: int, step_order: int}|null Next station + step order, or NULL if flow complete or next station inactive
     */
    public function calculateNextStation(Session $session): ?array
    {
        $overrideSteps = $session->override_steps;
        if (is_array($overrideSteps) && count($overrideSteps) > 0) {
            return $this->calculateNextFromOverrideSteps($session, $overrideSteps);
        }

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

    /**
     * Next station when session uses override_steps (custom one-off path).
     * override_steps is [station_id, ...]; step_order 1 = at index 0, so next = override_steps[step_order].
     *
     * @param  array<int>  $overrideSteps
     * @return array{station_id: int, step_order: int}|null
     */
    private function calculateNextFromOverrideSteps(Session $session, array $overrideSteps): ?array
    {
        $currentOrder = (int) ($session->current_step_order ?? 1);
        if ($currentOrder >= count($overrideSteps)) {
            return null;
        }

        $nextStationId = (int) $overrideSteps[$currentOrder];
        $station = \App\Models\Station::find($nextStationId);
        if (! $station || ! $station->is_active) {
            return null;
        }

        return [
            'station_id' => $nextStationId,
            'step_order' => $currentOrder + 1,
        ];
    }
}
