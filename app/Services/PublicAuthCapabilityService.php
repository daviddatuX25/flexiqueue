<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Program;
use App\Models\User;
use App\Support\PermissionCatalog;

/**
 * Central rules for public PIN/QR flows: Spatie permission + program/site context.
 * Used by session bypass (display settings) and documented for parity with APIs.
 */
class PublicAuthCapabilityService
{
    /**
     * Whether a logged-in user may skip interactive PIN/QR for a public.* action on this program.
     */
    public function userMaySkipInteractiveAuthFor(User $user, string $permission, Program $program): bool
    {
        if (! $user->can($permission)) {
            return false;
        }

        return match ($permission) {
            PermissionCatalog::PUBLIC_DISPLAY_SETTINGS_APPLY => $user->canBypassPublicDisplaySettingsPinForProgram($program->id),
            PermissionCatalog::PUBLIC_DEVICE_AUTHORIZE => $this->userMayAuthorizeDeviceForProgram($user, $program),
            default => true,
        };
    }

    /**
     * Same-site staff/admin (and super admin) as program; aligns with device lock bypass expectations.
     */
    public function userMayAuthorizeDeviceForProgram(User $user, Program $program): bool
    {
        if ($user->role === UserRole::SuperAdmin) {
            return true;
        }

        if (in_array($user->role, [UserRole::Admin, UserRole::Staff], true)) {
            return $user->site_id !== null && (int) $user->site_id === (int) $program->site_id;
        }

        return false;
    }
}
