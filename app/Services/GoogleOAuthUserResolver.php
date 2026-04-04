<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserCredential;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;

/**
 * Per HYBRID_AUTH_ADMIN_FIRST_PRD.md §3.2 AUTH-3–AUTH-4: match existing user by Google subject or recovery Gmail; no auto-provision.
 */
class GoogleOAuthUserResolver
{
    /**
     * Link flow: authenticated user must have Google email match {@see User::recovery_gmail}.
     * Fails if the Google subject is already linked to another account.
     *
     * @return bool true if linked or already linked to this user
     */
    public function attachGoogleToExistingUser(User $user, SocialiteUserContract $socialUser): bool
    {
        $sub = (string) $socialUser->getId();
        $email = $socialUser->getEmail();
        if ($email === null || $email === '') {
            return false;
        }

        $normalized = strtolower(trim($email));
        $recovery = $user->recovery_gmail !== null && $user->recovery_gmail !== ''
            ? strtolower(trim($user->recovery_gmail))
            : '';
        if ($recovery === '' || $normalized !== $recovery) {
            return false;
        }

        $existingForSub = UserCredential::query()
            ->where('provider', UserCredential::PROVIDER_GOOGLE)
            ->where('identifier', $sub)
            ->first();

        if ($existingForSub !== null) {
            return (int) $existingForSub->user_id === (int) $user->id;
        }

        UserCredential::query()
            ->where('user_id', $user->id)
            ->where('provider', UserCredential::PROVIDER_GOOGLE)
            ->delete();

        UserCredential::query()->create([
            'user_id' => $user->id,
            'provider' => UserCredential::PROVIDER_GOOGLE,
            'identifier' => $sub,
            'secret' => null,
        ]);

        return true;
    }

    public function findOrAttachUser(SocialiteUserContract $socialUser): ?User
    {
        $sub = (string) $socialUser->getId();
        $email = $socialUser->getEmail();
        if ($email === null || $email === '') {
            return null;
        }

        $existingCred = UserCredential::query()
            ->where('provider', UserCredential::PROVIDER_GOOGLE)
            ->where('identifier', $sub)
            ->first();

        if ($existingCred !== null) {
            return $existingCred->user;
        }

        $normalized = strtolower(trim($email));
        $user = User::query()
            ->whereNotNull('recovery_gmail')
            ->whereRaw('LOWER(TRIM(recovery_gmail)) = ?', [$normalized])
            ->first();

        if ($user === null) {
            return null;
        }

        UserCredential::query()->create([
            'user_id' => $user->id,
            'provider' => UserCredential::PROVIDER_GOOGLE,
            'identifier' => $sub,
            'secret' => null,
        ]);

        return $user->fresh();
    }
}
