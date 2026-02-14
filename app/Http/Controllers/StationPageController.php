<?php

namespace App\Http\Controllers;

use App\Models\Program;
use App\Models\Station;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per 09-UI-ROUTES-PHASE1 §3.3: Station page. Staff see assigned station; supervisor/admin can switch.
 */
class StationPageController extends Controller
{
    /**
     * Show station UI. Resolve station: route param > user's assigned > first available.
     */
    public function __invoke(Request $request, ?Station $station = null): Response
    {
        $user = $request->user();

        $resolvedStation = $station ?? ($user->assigned_station_id
            ? Station::find($user->assigned_station_id)
            : null);

        $program = Program::where('is_active', true)->first();
        $stationsList = [];
        if ($program) {
            $stationsList = $program->stations()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Station $s) => ['id' => $s->id, 'name' => $s->name])
                ->values()
                ->all();
        }

        return Inertia::render('Station/Index', [
            'station' => $resolvedStation ? [
                'id' => $resolvedStation->id,
                'name' => $resolvedStation->name,
            ] : null,
            'stations' => $stationsList,
            'canSwitchStation' => in_array($user->role->value ?? '', ['admin', 'supervisor'], true),
        ]);
    }
}
