<?php

namespace App\Services;

use App\Models\User;

/**
 * R6: Single place for post-save user provisioning — Spatie RBAC sync + local credentials row.
 * Invoked from {@see User::booted()} when role/username/password change or the user was just created
 * (not on every attribute save — e.g. availability-only updates skip this).
 */
final class UserProvisioningService
{
    public function __construct(
        private SpatieRbacSyncService $spatieRbacSyncService,
        private UserLocalCredentialService $userLocalCredentialService
    ) {}

    public function syncIdentityAndRbac(User $user): void
    {
        $this->spatieRbacSyncService->syncUser($user);
        $this->userLocalCredentialService->sync($user);
    }
}
