<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Per 05-SECURITY-CONTROLS §4: Supervisor PIN validation.
 * One-time verification for override/force-complete actions.
 */
class PinService
{
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

        if (! in_array($user->role, [UserRole::Admin, UserRole::Supervisor], true)) {
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
