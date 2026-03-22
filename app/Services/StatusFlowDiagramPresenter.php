<?php

namespace App\Services;

use App\Models\Program;
use App\Models\ProgramStationAssignment;
use App\Models\ServiceTrack;
use App\Models\Station;

/**
 * Builds diagram props for client flow view (Display status page + check-status JSON).
 */
class StatusFlowDiagramPresenter
{
    /**
     * @return array<string, mixed> Empty when program has no usable diagram.
     */
    public function propsForProgramTrack(int $programId, int $trackId, ?int $siteId = null): array
    {
        $q = Program::query()->with('diagram')->where('id', $programId);
        if ($siteId !== null) {
            $q->forSite($siteId);
        }
        $program = $q->first();
        if (! $program || ! $program->diagram) {
            return [];
        }

        $layout = $program->diagram->layout;
        if (! is_array($layout) || empty($layout['nodes'])) {
            return [];
        }

        $tracks = $program->serviceTracks()
            ->with(['trackSteps.process', 'trackSteps.station'])
            ->orderBy('name')
            ->get()
            ->map(fn (ServiceTrack $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'steps' => $t->trackSteps->map(fn ($s) => [
                    'station_id' => $s->station_id,
                    'process_id' => $s->process_id,
                    'step_order' => $s->step_order,
                ])->values()->all(),
            ])->values()->all();

        $processes = $program->processes()
            ->orderBy('name')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
            ])->values()->all();

        $stations = $program->stations()
            ->with('processes')
            ->orderBy('name')
            ->get()
            ->map(fn (Station $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'process_ids' => $s->processes->pluck('id')->values()->all(),
            ])->values()->all();

        $seen = [];
        $staffList = [];
        foreach (ProgramStationAssignment::where('program_id', $program->id)->with('user:id,name')->get() as $a) {
            $uid = $a->user_id;
            if (! in_array($uid, $seen, true)) {
                $seen[] = $uid;
                $staffList[] = ['id' => $a->user->id, 'name' => $a->user->name];
            }
        }
        foreach ($program->supervisedBy()->get() as $u) {
            if (! in_array($u->id, $seen, true)) {
                $seen[] = $u->id;
                $staffList[] = ['id' => $u->id, 'name' => $u->name];
            }
        }
        usort($staffList, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return [
            'diagram' => $layout,
            'diagram_program' => ['id' => $program->id, 'name' => $program->name],
            'diagram_tracks' => $tracks,
            'diagram_stations' => $stations,
            'diagram_processes' => $processes,
            'diagram_staff' => $staffList,
            'diagram_track_id' => $trackId,
        ];
    }
}
