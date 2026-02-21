<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin Users page with staff assignment. Per 09-UI-ROUTES-PHASE1, 08-API-SPEC §5.7.
 */
class UserPageController extends Controller
{
    public function index(): Response
    {
        $users = User::query()
            ->with('assignedStation')
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'avatar_url' => $u->avatar_url,
                'role' => $u->role->value,
                'is_active' => $u->is_active,
                'availability_status' => $u->availability_status ?? 'offline',
                'assigned_station_id' => $u->assigned_station_id,
                'assigned_station' => $u->assignedStation ? [
                    'id' => $u->assignedStation->id,
                    'name' => $u->assignedStation->name,
                ] : null,
            ]);

        $program = Program::where('is_active', true)->first();
        $stations = [];
        if ($program) {
            $stations = $program->stations()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])
                ->values()
                ->all();
        }

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'stations' => $stations,
        ]);
    }
}
