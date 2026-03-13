<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AdminActionLog;
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
use Illuminate\Validation\ValidationException;

/**
 * Per 08-API-SPEC-PHASE1 §5.6, §5.7: User CRUD, staff assignment to stations.
 * Per central-edge B.4: users are scoped by authenticated user's site_id.
 * Per assign-site-to-user follow-up: super_admin sees all users and can set site_id.
 */
class UserController extends Controller
{
    /**
     * List users with assigned station. Site-scoped for admin; super_admin sees all (optional ?site_id= filter).
     */
    public function index(Request $request): JsonResponse
    {
        $authUser = $request->user();
        $query = User::query()->with(['assignedStation', 'site'])->orderBy('name');

        if ($authUser->isSuperAdmin()) {
            $query->where('role', UserRole::Admin->value);
            $siteId = $request->query('site_id');
            if (is_numeric($siteId)) {
                $query->forSite((int) $siteId);
            }
        } else {
            $siteId = $authUser->site_id;
            if ($siteId === null) {
                return response()->json(['users' => []]);
            }
            $query->forSite($siteId);
        }

        $users = $query->get()->map(fn (User $u) => [
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
            'site' => $u->site ? ['id' => $u->site->id, 'name' => $u->site->name, 'slug' => $u->site->slug] : null,
        ]);

        return response()->json(['users' => $users]);
    }

    /**
     * Assign user to station. Per spec §5.7. Per B.4: 404 if user not in site.
     */
    public function assignStation(Request $request, User $user): JsonResponse
    {
        $this->ensureUserInSite($request, $user);

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
     * Remove station assignment. Per spec §5.7. Per B.4: 404 if user not in site.
     */
    public function unassignStation(Request $request, User $user): JsonResponse
    {
        $this->ensureUserInSite($request, $user);

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
     * Create user. Per 08-API-SPEC-PHASE1 §5.6. Per B.4: site_id from auth; 403 if admin has no site.
     * RBAC: super_admin may only create admin accounts; site admin may only create staff accounts. Never allow role super_admin.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $authUser = $request->user();
        $valid = $request->validated();

        $requestedRole = isset($valid['role']) ? $valid['role'] : null;
        if ($requestedRole === UserRole::SuperAdmin->value) {
            throw ValidationException::withMessages(['role' => ['Creating a super admin via API is not allowed.']]);
        }
        if ($authUser->isSuperAdmin()) {
            if ($requestedRole !== UserRole::Admin->value) {
                throw ValidationException::withMessages(['role' => ['Super admin may only create admin accounts.']]);
            }
        } else {
            if ($authUser->site_id === null) {
                abort(403, 'You must be assigned to a site to create users.');
            }
            $allowedRoles = [UserRole::Staff->value, UserRole::Admin->value];
            if ($requestedRole === null || ! in_array($requestedRole, $allowedRoles, true)) {
                throw ValidationException::withMessages(['role' => ['Site admin may only create staff or admin accounts for their site.']]);
            }
        }

        $siteId = null;
        if ($authUser->isSuperAdmin() && array_key_exists('site_id', $valid) && $valid['site_id'] !== null) {
            $siteId = (int) $valid['site_id'];
        } elseif (! $authUser->isSuperAdmin()) {
            $siteId = $authUser->site_id;
        }

        $user = new User([
            'site_id' => $siteId,
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

        AdminActionLog::log($authUser->id, 'user_created', 'User', $user->id, ['email' => $user->email, 'role' => $user->role->value]);

        return response()->json([
            'user' => $this->userResource($user),
        ], 201);
    }

    /**
     * Update user. Per 08-API-SPEC-PHASE1 §5.6. Per B.4: 404 if user not in site.
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->ensureUserInSite($request, $user);

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
        if (array_key_exists('role', $valid)) {
            if ($user->id === $request->user()->id) {
                throw ValidationException::withMessages(['role' => ['You cannot change your own role.']]);
            }
            $requestedRole = $valid['role'];
            if ($requestedRole === UserRole::SuperAdmin->value) {
                throw ValidationException::withMessages(['role' => ['Assigning super admin role via API is not allowed.']]);
            }
            if (! $request->user()->isSuperAdmin() && $requestedRole === UserRole::Admin->value) {
                throw ValidationException::withMessages(['role' => ['Site admin may not assign the admin role.']]);
            }
            $user->role = $requestedRole;
        }
        if (array_key_exists('is_active', $valid)) {
            $user->is_active = (bool) $valid['is_active'];
        }
        if (array_key_exists('override_pin', $valid)) {
            $user->override_pin = $valid['override_pin'] ? Hash::make($valid['override_pin']) : null;
        }
        if ($request->user()->isSuperAdmin() && array_key_exists('site_id', $valid)) {
            $user->site_id = $valid['site_id'] ? (int) $valid['site_id'] : null;
        }

        $user->save();

        AdminActionLog::log($request->user()->id, 'user_updated', 'User', $user->id, ['email' => $user->email]);

        return response()->json([
            'user' => $this->userResource($user),
        ]);
    }

    /**
     * Deactivate user (soft). Per 08-API-SPEC-PHASE1 §5.6. Per B.4: 404 if user not in site.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            abort(403, 'You cannot delete your own account.');
        }
        $this->ensureUserInSite($request, $user);

        $user->update(['is_active' => false]);

        AdminActionLog::log($request->user()->id, 'user_deactivated', 'User', $user->id, ['email' => $user->email]);

        return response()->json([
            'user' => $this->userResource($user),
        ]);
    }

    /**
     * Reset user password (admin sets new password). Per B.4: 404 if user not in site.
     */
    public function resetPassword(ResetPasswordRequest $request, User $user): JsonResponse
    {
        $this->ensureUserInSite($request, $user);

        $user->update(['password' => Hash::make($request->validated('password'))]);

        return response()->json(['user_id' => $user->id]);
    }

    /**
     * Per central-edge B.4: ensure admin has a site and user belongs to it. 403 if no site, 404 if wrong site.
     * Per SUPER-ADMIN-VS-ADMIN-SPEC: super_admin may only access users with role admin (not staff).
     */
    private function ensureUserInSite(Request $request, User $user): void
    {
        if ($request->user()->isSuperAdmin()) {
            if ($user->role !== UserRole::Admin) {
                abort(403, 'Super admin may only manage admin accounts.');
            }

            return;
        }
        $siteId = $request->user()->site_id;
        if ($siteId === null) {
            abort(403, 'You must be assigned to a site to access this resource.');
        }
        if ($user->site_id !== $siteId) {
            abort(404);
        }
    }

    private function userResource(User $user): array
    {
        $user->load(['assignedStation', 'site']);

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
            'site' => $user->site ? ['id' => $user->site->id, 'name' => $user->site->name, 'slug' => $user->site->slug] : null,
        ];
    }
}
