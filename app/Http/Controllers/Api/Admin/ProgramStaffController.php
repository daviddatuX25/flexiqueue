<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Models\ProgramStationAssignment;
use App\Models\Station;
use App\Models\User;
use App\Services\ProgramSupervisorGrantService;
use App\Services\SpatieRbacSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per refactor plan: program-scoped staff assignments and supervisors.
 * Per central-edge B.4: program and users must belong to admin's site.
 */
class ProgramStaffController extends Controller
{
    /**
     * List staff with their station assignment for this program. Per B.4: 404 if program not in site.
     */
    public function staffAssignments(Request $request, Program $program): JsonResponse
    {
        $this->ensureProgramInSite($request, $program);

        $siteId = $request->user()->site_id;
        $assignments = ProgramStationAssignment::query()
            ->where('program_id', $program->id)
            ->with(['user:id,name,email,is_active,site_id', 'station:id,name'])
            ->get();

        $staffIds = $assignments->pluck('user_id')->unique()->all();
        $staffWithoutAssignment = User::withGlobalPermissionsTeam(fn () => User::query()
            ->forSite($siteId)
            ->role(UserRole::Staff->value)
            ->where('is_active', true)
            ->whereNotIn('id', $staffIds)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'is_active']));

        $items = $assignments->map(fn ($a) => [
            'user_id' => $a->user_id,
            'user' => [
                'id' => $a->user->id,
                'name' => $a->user->name,
                'email' => $a->user->email,
                'role' => $a->user->primaryGlobalRoleName() ?? 'staff',
                'is_active' => $a->user->is_active,
            ],
            'station_id' => $a->station_id,
            'station' => ['id' => $a->station->id, 'name' => $a->station->name],
        ])->values()->all();

        $unassigned = $staffWithoutAssignment->map(fn ($u) => [
            'user_id' => $u->id,
            'user' => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->primaryGlobalRoleName() ?? 'staff',
                'is_active' => $u->is_active,
            ],
            'station_id' => null,
            'station' => null,
        ])->values()->all();

        return response()->json([
            'assignments' => array_merge($items, $unassigned),
            'stations' => $program->stations()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'capacity'])->all(),
        ]);
    }

    /**
     * Assign staff to station for this program. Per B.4: 404 if program or user not in site.
     */
    public function assignStaff(Request $request, Program $program): JsonResponse
    {
        $this->ensureProgramInSite($request, $program);

        $valid = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'station_id' => ['required', 'integer', 'exists:stations,id'],
        ]);

        $station = Station::findOrFail($valid['station_id']);
        if ((int) $station->program_id !== (int) $program->id) {
            return response()->json(['message' => 'Station does not belong to this program.'], 422);
        }

        $user = User::forSite($request->user()->site_id)->findOrFail($valid['user_id']);
        if (! $user->isStaff()) {
            return response()->json(['message' => 'Only staff can be assigned to stations.'], 422);
        }

        // Per central-edge follow-up: allow multi-program assignment; warn when adding to a second program.
        $otherProgramAssignment = ProgramStationAssignment::query()
            ->where('user_id', $user->id)
            ->where('program_id', '!=', $program->id)
            ->exists();

        ProgramStationAssignment::updateOrCreate(
            ['program_id' => $program->id, 'user_id' => $user->id],
            ['station_id' => $station->id]
        );

        // Per central-edge Phase A: set user's assigned station when assigning in this program.
        $user->update(['assigned_station_id' => $station->id]);

        $payload = [
            'user_id' => $user->id,
            'station_id' => $station->id,
        ];
        if ($otherProgramAssignment) {
            $payload['warning'] = 'This staff is already assigned to another program. On the day, they can only work in one program at a time (they will choose or be assigned to one).';
        }

        return response()->json($payload, 201);
    }

    /**
     * Unassign staff from station for this program. Per B.4: 404 if program or user not in site.
     */
    public function unassignStaff(Request $request, Program $program, User $user): JsonResponse
    {
        $this->ensureProgramInSite($request, $program);
        $this->ensureUserInSite($request, $user);

        ProgramStationAssignment::query()
            ->where('program_id', $program->id)
            ->where('user_id', $user->id)
            ->delete();

        // Per central-edge Phase A: clear user's assigned station if it was in this program.
        if ($user->assigned_station_id && $user->assignedStation?->program_id === $program->id) {
            $user->update(['assigned_station_id' => null]);
        }

        return response()->json(['user_id' => $user->id, 'station_id' => null]);
    }

    /**
     * List supervisors for this program. Per B.4: 404 if program not in site; staff list site-scoped.
     */
    public function supervisors(Request $request, Program $program): JsonResponse
    {
        $this->ensureProgramInSite($request, $program);

        $siteId = $request->user()->site_id;
        $allSupervisorIds = $program->allSupervisorUserIds();

        $supervisors = User::withGlobalPermissionsTeam(fn () => User::query()
            ->whereIn('id', $allSupervisorIds)
            ->role(UserRole::Staff->value)
            ->orderBy('name')
            ->get(['id', 'name', 'email']));

        $staffWithPin = User::withGlobalPermissionsTeam(fn () => User::query()
            ->forSite($siteId)
            ->role(UserRole::Staff->value)
            ->where('is_active', true)
            ->whereNotNull('override_pin')
            ->orderBy('name')
            ->get(['id', 'name', 'email']));

        $supervisorIds = $supervisors->pluck('id')->all();

        return response()->json([
            'supervisors' => $supervisors->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
            ])->values()->all(),
            'staff_with_pin' => $staffWithPin->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'is_supervisor' => in_array($u->id, $supervisorIds),
            ])->values()->all(),
        ]);
    }

    /**
     * Add supervisor for this program (staff with override_pin). Per B.4: 404 if program or user not in site.
     */
    public function addSupervisor(Request $request, Program $program): JsonResponse
    {
        $this->ensureProgramInSite($request, $program);

        $valid = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);

        $user = User::forSite($request->user()->site_id)->findOrFail($valid['user_id']);
        if (! $user->isStaff()) {
            return response()->json(['message' => 'Only staff can be program supervisors.'], 422);
        }
        if (! $user->override_pin) {
            return response()->json(['message' => 'User must have override PIN set to be a supervisor.'], 422);
        }

        app(ProgramSupervisorGrantService::class)->grantProgramTeamSupervise($user, $program);

        app(SpatieRbacSyncService::class)->syncSupervisorDirectPermissions($user->fresh());

        return response()->json([
            'user_id' => $user->id,
            'name' => $user->name,
        ], 201);
    }

    /**
     * Remove supervisor from this program. Per B.4: 404 if program or user not in site.
     */
    public function removeSupervisor(Request $request, Program $program, User $user): JsonResponse
    {
        $this->ensureProgramInSite($request, $program);
        $this->ensureUserInSite($request, $user);

        app(ProgramSupervisorGrantService::class)->revokeProgramTeamSupervise($user, $program);

        app(SpatieRbacSyncService::class)->syncSupervisorDirectPermissions($user->fresh());

        return response()->json(['user_id' => $user->id]);
    }

    /** Per central-edge B.4: 403 if no site, 404 if program not in site. */
    private function ensureProgramInSite(Request $request, Program $program): void
    {
        $siteId = $request->user()->site_id;
        if ($siteId === null) {
            abort(403, 'You must be assigned to a site to access this resource.');
        }
        if ($program->site_id !== $siteId) {
            abort(404);
        }
    }

    /** Per central-edge B.4: 403 if no site, 404 if user not in site. */
    private function ensureUserInSite(Request $request, User $user): void
    {
        $siteId = $request->user()->site_id;
        if ($siteId === null) {
            abort(403, 'You must be assigned to a site to access this resource.');
        }
        if ($user->site_id !== $siteId) {
            abort(404);
        }
    }
}
