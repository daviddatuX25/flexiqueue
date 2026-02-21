<?php

namespace App\Services;

use App\Models\Session;
use App\Models\TrackStep;

/**
 * Per 03-FLOW-ENGINE §2: Routing algorithm for session flow.
 * Per PROCESS-STATION-REFACTOR: returns process_id when TrackStep has process; else station_id (dual-read).
 */
class FlowEngine
{
    /**
     * Calculate the next station or process for a session in standard flow.
     * Per TRACK-OVERRIDES-REFACTOR: when session.override_steps is set, returns station_id.
     * Per PROCESS-STATION-REFACTOR: when TrackStep has process_id, returns process_id; else station_id (dual-read).
     *
     * @return array{station_id?: int, process_id?: int, step_order: int}|null Next station/process + step order, or NULL if flow complete or inactive
     */
    public function calculateNextStation(Session $session): ?array
    {
        $overrideSteps = $session->override_steps;
        if (is_array($overrideSteps) && count($overrideSteps) > 0) {
            return $this->calculateNextFromOverrideSteps($session, $overrideSteps);
        }

        $nextStep = TrackStep::where('track_id', $session->track_id)
            ->where('step_order', $session->current_step_order + 1)
            ->with(['process'])
            ->first();

        if (! $nextStep) {
            return null;
        }

        // Per PROCESS-STATION-REFACTOR Phase 3: process_id required; caller uses StationSelectionService
        if ($nextStep->process_id !== null) {
            return [
                'process_id' => $nextStep->process_id,
                'step_order' => $nextStep->step_order,
            ];
        }

        // No process_id: no next station (Phase 3 removed station_id fallback)
        return null;
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
