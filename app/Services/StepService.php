<?php

namespace App\Services;

use App\Models\ServiceTrack;

/**
 * Step-level business logic extracted from StepController.
 * Per docs/quality/ISSUES.md #25: raw DB joins do not belong in controllers.
 */
class StepService
{
    /**
     * Build a station_id → step_order map for the given track.
     *
     * Uses the TrackStep → Process → stations Eloquent chain instead of a
     * raw DB::table join, so authorization/site-scoping from the model
     * relationships is preserved.
     *
     * Used when remapping active sessions to the new order after a reorder.
     *
     * @return array<int, int>  station_id => step_order
     */
    public function getStationToStepOrderMap(ServiceTrack $track): array
    {
        $map = [];
        foreach ($track->trackSteps()->with('process.stations')->get() as $step) {
            if ($step->process === null) {
                continue;
            }
            foreach ($step->process->stations as $station) {
                $map[(int) $station->id] = (int) $step->step_order;
            }
        }

        return $map;
    }
}
