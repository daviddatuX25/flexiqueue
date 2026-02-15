<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Models\ProgramStationAssignment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per refactor plan: program-scoped staff assignments and supervisors.
 */
class ProgramStaffController extends Controller
{
    /**
     * List staff with their station assignment for this program.
     */
    public function staffAssignments(Program $program): JsonResponse
    {
        $assignments = ProgramStationAssignment::query()
            ->where('program_id', $program->id)
            ->with(['user:id,name,email,role,is_active', 'station:id,name'])
            ->get();

        $staffIds = $assignments->pluck('user_id')->unique()->all();
        $staffWithoutAssignment = User::query()
            ->where('role', 'staff')
            ->where('is_active', true)
            ->whereNotIn('id', $staffIds)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'is_active']);

        $items = $assignments->map(fn ($a) => [
            'user_id' => $a->user_id,
            'user' => [
                'id' => $a->user->id,
                'name' => $a->user->name,
                'email' => $a->user->email,
                'role' => $a->user->role->value,
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
                'role' => $u->role->value,
                'is_active' => $u->is_active,
            ],
            'station_id' => null,
            'station' => null,
        ])->values()->all();

        return response()->json([
            'assignments' => array_merge($items, $unassigned),
            'stations' => $program->stations()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->all(),
        ]);
    }

    /**
     * Assign staff to station for this program.
     */
    public function assignStaff(Request $request, Program $program): JsonResponse
    {
        $valid = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'station_id' => ['required', 'integer', 'exists:stations,id'],
        ]);

        $station = \App\Models\Station::findOrFail($valid['station_id']);
        if ((int) $station->program_id !== (int) $program->id) {
            return response()->json(['message' => 'Station does not belong to this program.'], 422);
        }

        $user = User::findOrFail($valid['user_id']);
        if ($user->role->value !== 'staff') {
            return response()->json(['message' => 'Only staff can be assigned to stations.'], 422);
        }

        ProgramStationAssignment::updateOrCreate(
            ['program_id' => $program->id, 'user_id' => $user->id],
            ['station_id' => $station->id]
        );

        $activeProgram = Program::query()->where('is_active', true)->first();
        if ($activeProgram && (int) $activeProgram->id === (int) $program->id) {
            $user->update(['assigned_station_id' => $station->id]);
        }

        return response()->json([
            'user_id' => $user->id,
            'station_id' => $station->id,
        ], 201);
    }

    /**
     * Unassign staff from station for this program.
     */
    public function unassignStaff(Program $program, User $user): JsonResponse
    {
        ProgramStationAssignment::query()
            ->where('program_id', $program->id)
            ->where('user_id', $user->id)
            ->delete();

        $activeProgram = Program::query()->where('is_active', true)->first();
        if ($activeProgram && (int) $activeProgram->id === (int) $program->id) {
            $user->update(['assigned_station_id' => null]);
        }

        return response()->json(['user_id' => $user->id, 'station_id' => null]);
    }

    /**
     * List supervisors for this program.
     */
    public function supervisors(Program $program): JsonResponse
    {
        $supervisors = $program->supervisedBy()
            ->where('users.role', 'staff')
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.email']);

        $staffWithPin = User::query()
            ->where('role', 'staff')
            ->where('is_active', true)
            ->whereNotNull('override_pin')
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

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
     * Add supervisor for this program (staff with override_pin).
     */
    public function addSupervisor(Request $request, Program $program): JsonResponse
    {
        $valid = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);

        $user = User::findOrFail($valid['user_id']);
        if ($user->role->value !== 'staff') {
            return response()->json(['message' => 'Only staff can be program supervisors.'], 422);
        }
        if (! $user->override_pin) {
            return response()->json(['message' => 'User must have override PIN set to be a supervisor.'], 422);
        }

        $program->supervisedBy()->syncWithoutDetaching([$user->id]);

        return response()->json([
            'user_id' => $user->id,
            'name' => $user->name,
        ], 201);
    }

    /**
     * Remove supervisor from this program.
     */
    public function removeSupervisor(Program $program, User $user): JsonResponse
    {
        $program->supervisedBy()->detach($user->id);

        return response()->json(['user_id' => $user->id]);
    }
}
