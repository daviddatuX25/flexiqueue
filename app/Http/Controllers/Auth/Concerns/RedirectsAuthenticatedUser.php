<?php

namespace App\Http\Controllers\Auth\Concerns;

use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Http\RedirectResponse;

trait RedirectsAuthenticatedUser
{
    /**
     * Post-login destination: admin shell when {@see PermissionCatalog::ADMIN_SHARED} applies;
     * otherwise staff pending onboarding, then station.
     */
    private function redirectAfterLogin(User $user): RedirectResponse
    {
        if ($user->can(PermissionCatalog::ADMIN_SHARED)) {
            return redirect()->route('admin.dashboard');
        }

        if ($user->isStaff() && $user->pending_assignment) {
            return redirect()->route('pending-assignment');
        }

        return redirect()->route('station');
    }
}
