<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Program;
use App\Models\TemporaryAuthorization;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Per 05-SECURITY-CONTROLS §4: Supervisor PIN validation.
 * One-time verification for override/force-complete actions.
 */
class PinService
{
    /**
     * Validate temporary PIN (configurable expiry mode: time-only, usage-only, or time-or-usage).
     *
     * @return array{verified: true, user_id: int, role: string}|null
     */
    public function validateTemporaryPin(string $tempCode): ?array
    {
        $auths = $this->candidateTemporaryAuthorizations('pin');

        foreach ($auths as $auth) {
            if (Hash::check($tempCode, $auth->token_hash)) {
                if (! $this->consumeTemporaryAuthorization($auth)) {
                    return null;
                }
                $user = $auth->user;

                return [
                    'verified' => true,
                    'user_id' => $user->id,
                    'role' => $user->primaryGlobalRoleName() ?? 'staff',
                ];
            }
        }

        return null;
    }

    /**
     * Validate temporary QR scan token (configurable expiry mode: time-only, usage-only, or time-or-usage).
     *
     * @return array{verified: true, user_id: int, role: string}|null
     */
    public function validateTemporaryQr(string $qrScanToken): ?array
    {
        $auths = $this->candidateTemporaryAuthorizations('qr');

        foreach ($auths as $auth) {
            if (Hash::check($qrScanToken, $auth->token_hash)) {
                if (! $this->consumeTemporaryAuthorization($auth)) {
                    return null;
                }
                $user = $auth->user;

                return [
                    'verified' => true,
                    'user_id' => $user->id,
                    'role' => $user->primaryGlobalRoleName() ?? 'staff',
                ];
            }
        }

        return null;
    }

    /**
     * Pull a bounded set of candidate temporary authorizations to check via Hash::check().
     * We can't look up by hash directly, so we filter by type and likely-not-expired conditions.
     *
     * @return Collection<int, TemporaryAuthorization>
     */
    private function candidateTemporaryAuthorizations(string $type)
    {
        $now = now();

        return TemporaryAuthorization::query()
            ->where('type', $type)
            ->whereNull('used_at') // Legacy single-use: consumed rows had used_at set; exclude them so they cannot be reused
            ->where(function ($q) use ($now) {
                // Keep usage-only rows even if expires_at is null/old; time gating is not relevant there.
                $q->where('expiry_mode', 'usage_only')
                    ->orWhereNull('expires_at')
                    ->orWhere('expires_at', '>', $now);
            })
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();
    }

    /**
     * Consume one use of a temporary authorization, atomically when usage-limited.
     * Returns false if it is expired/consumed at the time of use.
     */
    private function consumeTemporaryAuthorization(TemporaryAuthorization $auth): bool
    {
        $mode = $auth->expiry_mode ?: 'time_or_usage';
        $now = now();

        if ($mode === 'time_only') {
            // Unlimited uses until TTL; record usage telemetry but don't gate.
            if ($auth->expires_at !== null && $auth->expires_at->isPast()) {
                return false;
            }

            TemporaryAuthorization::whereKey($auth->id)->update([
                'used_count' => DB::raw('used_count + 1'),
                'last_used_at' => $now,
            ]);

            return true;
        }

        $query = TemporaryAuthorization::query()->whereKey($auth->id);

        if ($mode !== 'usage_only') {
            $query->where(function ($q) use ($now) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            });
        }

        $query->whereNotNull('max_uses')->whereColumn('used_count', '<', 'max_uses');

        $updated = $query->update([
            'used_count' => DB::raw('used_count + 1'),
            'last_used_at' => $now,
        ]);

        return $updated === 1;
    }

    /**
     * Validate preset PIN. Preset is per-user (not tied to program); any user with override_pin can be validated.
     * Caller must then check the user is allowed to authorize for the given program (admin or supervisor for that program).
     *
     * @return array{verified: true, user_id: int, role: string}|null User info on success, null on failure
     */
    public function validate(int $userId, string $pin): ?array
    {
        $user = User::find($userId);
        if (! $user || ! $user->override_pin) {
            return null;
        }

        if (! Hash::check($pin, $user->override_pin)) {
            return null;
        }

        return [
            'verified' => true,
            'user_id' => $user->id,
            'role' => $user->primaryGlobalRoleName() ?? 'staff',
        ];
    }

    /**
     * Validate preset QR scan token (user's persistent override_qr_token).
     * Preset is per-user; any user with override_qr_token set can be validated.
     * Caller must then check the user is allowed to authorize for the given program (admin or supervisor for that program).
     *
     * @return array{verified: true, user_id: int, role: string}|null
     */
    public function validatePresetQr(string $qrScanToken): ?array
    {
        if ($qrScanToken === '') {
            return null;
        }

        $users = User::query()
            ->whereNotNull('override_qr_token')
            ->get();

        foreach ($users as $user) {
            if (Hash::check($qrScanToken, $user->override_qr_token)) {
                return [
                    'verified' => true,
                    'user_id' => $user->id,
                    'role' => $user->primaryGlobalRoleName() ?? 'staff',
                ];
            }
        }

        return null;
    }

    /**
     * Validate PIN for public display-settings: PIN must match a supervisor of the program or an admin.
     * Per central-edge Phase A: programId from request; no single-active.
     *
     * @return array{verified: true, user_id: int, role: string}|null
     */
    public function validatePinForProgram(int $programId, string $pin): ?array
    {
        $program = Program::find($programId);
        if (! $program) {
            return null;
        }

        $supervisorIds = collect($program->allSupervisorUserIds());
        $adminIds = User::withGlobalPermissionsTeam(fn () => User::query()
            ->role([UserRole::Admin->value, UserRole::SuperAdmin->value])
            ->pluck('id'));
        $userIds = $supervisorIds->merge($adminIds)->unique()->values()->all();

        $users = User::query()
            ->whereIn('id', $userIds)
            ->whereNotNull('override_pin')
            ->get();

        foreach ($users as $user) {
            if (Hash::check($pin, $user->override_pin)) {
                return [
                    'verified' => true,
                    'user_id' => $user->id,
                    'role' => $user->primaryGlobalRoleName() ?? 'staff',
                ];
            }
        }

        return null;
    }

    /**
     * Validate preset QR for device authorization: QR must match a user who is a supervisor of the program or an admin.
     * Per plan Step 5: used by public device-authorize API.
     *
     * @return array{verified: true, user_id: int, role: string}|null
     */
    public function validateQrForProgram(int $programId, string $qrScanToken): ?array
    {
        $result = $this->validatePresetQr($qrScanToken);
        if (! $result) {
            return null;
        }

        $program = Program::find($programId);
        if (! $program) {
            return null;
        }

        $userId = $result['user_id'];
        $user = User::find($userId);
        $isSupervisor = $user && $user->isSupervisorForProgram($program->id);
        $hasAdminShell = $user && $user->can(PermissionCatalog::ADMIN_SHARED);

        if (! $isSupervisor && ! $hasAdminShell) {
            return null;
        }

        return $result;
    }
}
