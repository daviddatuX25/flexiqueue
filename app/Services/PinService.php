<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\TemporaryAuthorization;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Per 05-SECURITY-CONTROLS §4: Supervisor PIN validation.
 * One-time verification for override/force-complete actions.
 */
class PinService
{
    /**
     * Validate temporary PIN (single-use, expires after TTL).
     * Per PIN-QR-AUTHORIZATION-SYSTEM §3.1: Used twice → reject; Expired → 401.
     *
     * @return array{verified: true, user_id: int, role: string}|null
     */
    public function validateTemporaryPin(string $tempCode): ?array
    {
        $auths = TemporaryAuthorization::where('type', 'pin')
            ->whereNull('used_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->get();

        foreach ($auths as $auth) {
            if (Hash::check($tempCode, $auth->token_hash)) {
                $auth->update(['used_at' => now()]);
                $user = $auth->user;
                return [
                    'verified' => true,
                    'user_id' => $user->id,
                    'role' => $user->role->value,
                ];
            }
        }

        // Check if it was used or expired (for error message)
        $authsAll = TemporaryAuthorization::where('type', 'pin')->get();
        foreach ($authsAll as $auth) {
            if (Hash::check($tempCode, $auth->token_hash)) {
                return null; // Used or expired
            }
        }

        return null;
    }
    /**
     * Validate supervisor PIN for override actions.
     *
     * @return array{verified: true, user_id: int, role: string}|null User info on success, null on failure
     */
    public function validate(int $userId, string $pin): ?array
    {
        $user = User::find($userId);
        if (! $user || ! $user->override_pin) {
            return null;
        }

        if ($user->isAdmin()) {
            // Admin can always validate
        } elseif ($user->role === UserRole::Staff && $user->isSupervisorForAnyProgram()) {
            // Staff who is supervisor for at least one program
        } else {
            return null;
        }

        if (! Hash::check($pin, $user->override_pin)) {
            return null;
        }

        return [
            'verified' => true,
            'user_id' => $user->id,
            'role' => $user->role->value,
        ];
    }
}
