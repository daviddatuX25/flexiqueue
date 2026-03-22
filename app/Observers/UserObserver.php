<?php

namespace App\Observers;

use App\Models\User;
use App\Services\SpatieRbacSyncService;

class UserObserver
{
    public function __construct(
        private SpatieRbacSyncService $spatieRbacSyncService
    ) {}

    public function saved(User $user): void
    {
        $this->spatieRbacSyncService->syncUser($user);
    }
}
