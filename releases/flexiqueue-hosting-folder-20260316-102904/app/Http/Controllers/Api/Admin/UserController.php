<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\ProgramStationAssignment;
use App\Models\Station;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Per 08-API-SPEC-PHASE1 §5.6, §5.7: User CRUD, staff assignment to stations.
 */
class UserController extends Controller
{
    /**
     * List users with assigned station. Admin only.
     */
    public function index(): JsonResponse
    {
        $users = User::query()
            ->with('assignedStation')
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role->value,
                'is_active' => $u->is_active,
                'assigned_station_id' => $u->assigned_station_id,
                'assigned_station' => $u->assignedStation ? [
                    'id' => $u->assignedStation->id,
                    'name' => $u->assignedStation->name,
                ] : null,
            ]);

        return response()->json(['users' => $users]);
    }

    /**
     * Assign user to station. Per spec §5.7.
     */
    public function assignStation(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'station_id' => ['required', 'integer', 'exists:stations,id'],
        ]);

        $station = Station::findOrFail($request->station_id);
        $programId = $station->program_id;

        ProgramStationAssignment::updateOrCreate(
            ['program_id' => $programId, 'user_id' => $user->id],
            ['station_id' => $station->id]
        );
        $user->update(['assigned_station_id' => $station->id]);

        $user->load('assignedStation');

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'assigned_station_id' => $user->assigned_station_id,
                'assigned_station' => $user->assignedStation ? [
                    'id' => $user->assignedStation->id,
                    'name' => $user->assignedStation->name,
                ] : null,
            ],
        ]);
    }

    /**
     * Remove station assignment. Per spec §5.7.
     */
    public function unassignStation(User $user): JsonResponse
    {
        $stationId = $user->assigned_station_id;
        if ($stationId) {
            $station = Station::find($stationId);
            if ($station) {
                ProgramStationAssignment::query()
                    ->where('program_id', $station->program_id)
                    ->where('user_id', $user->id)
                    ->delete();
            }
        }
        $user->update(['assigned_station_id' => null]);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'assigned_station_id' => null,
                'assigned_station' => null,
            ],
        ]);
    }

    /**
     * Create user. Per 08-API-SPEC-PHASE1 §5.6.
     * Every user gets a default preset PIN and preset QR (saved as default); admin cannot view the PIN.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $valid = $request->validated();
        $user = new User([
            'name' => $valid['name'],
            'email' => $valid['email'],
            'password' => Hash::make($valid['password']),
            'role' => $valid['role'],
            'is_active' => true,
            'override_pin' => ! empty($valid['override_pin'])
                ? Hash::make(trim($valid['override_pin']))
                : Hash::make((string) random_int(100000, 999999)),
            'override_qr_token' => Hash::make(Str::random(64)),
        ]);
        $user->save();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'is_active' => $user->is_active,
            ],
        ], 201);
    }

    /**
     * Update user. Per 08-API-SPEC-PHASE1 §5.6.
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $valid = $request->validated();

        if (isset($valid['name'])) {
            $user->name = $valid['name'];
        }
        if (isset($valid['email'])) {
            $user->email = $valid['email'];
        }
        if (! empty($valid['password'])) {
            $user->password = Hash::make($valid['password']);
        }
        if (isset($valid['role'])) {
            $user->role = $valid['role'];
        }
        if (array_key_exists('is_active', $valid)) {
            $user->is_active = (bool) $valid['is_active'];
        }
        if (array_key_exists('override_pin', $valid)) {
            $user->override_pin = $valid['override_pin'] ? Hash::make($valid['override_pin']) : null;
        }

        $user->save();

        return response()->json([
            'user' => $this->userResource($user),
        ]);
    }

    /**
     * Deactivate user (soft). Per 08-API-SPEC-PHASE1 §5.6.
     */
    public function destroy(User $user): JsonResponse
    {
        $user->update(['is_active' => false]);

        return response()->json([
            'user' => $this->userResource($user),
        ]);
    }

    /**
     * Reset user password (admin sets new password).
     */
    public function resetPassword(ResetPasswordRequest $request, User $user): JsonResponse
    {
        $user->update(['password' => Hash::make($request->validated('password'))]);

        return response()->json(['user_id' => $user->id]);
    }

    private function userResource(User $user): array
    {
        $user->load('assignedStation');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'is_active' => $user->is_active,
            'assigned_station_id' => $user->assigned_station_id,
            'assigned_station' => $user->assignedStation ? [
                'id' => $user->assignedStation->id,
                'name' => $user->assignedStation->name,
            ] : null,
        ];
    }
}
