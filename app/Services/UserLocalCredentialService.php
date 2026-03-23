<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserCredential;
use Illuminate\Support\Facades\DB;

/**
 * Per HYBRID_AUTH_ADMIN_FIRST_PRD.md §4.1: keep user_credentials (local) aligned with users.username + users.password.
 */
class UserLocalCredentialService
{
    public function sync(User $user): void
    {
        if ($user->username === null || $user->username === '') {
            return;
        }

        $passwordHash = $user->getRawOriginal('password');
        if ($passwordHash === null || $passwordHash === '') {
            $passwordHash = DB::table('users')->where('id', $user->id)->value('password');
        }

        if ($passwordHash === null || $passwordHash === '') {
            return;
        }

        UserCredential::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'provider' => UserCredential::PROVIDER_LOCAL,
            ],
            [
                'identifier' => $user->username,
                'secret' => $passwordHash,
            ]
        );
    }
}
