<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Models\Station;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin Reports page (audit log viewer + export). Per 09-UI-ROUTES-PHASE1 §3.11.
 */
class ReportPageController extends Controller
{
    public function index(): Response
    {
        $programs = Program::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])
            ->values()
            ->all();

        $stations = Station::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'program_id'])
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'program_id' => $s->program_id])
            ->values()
            ->all();

        $staffUsers = User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])
            ->values()
            ->all();

        return Inertia::render('Admin/Reports/Index', [
            'programs' => $programs,
            'stations' => $stations,
            'staffUsers' => $staffUsers,
        ]);
    }
}
