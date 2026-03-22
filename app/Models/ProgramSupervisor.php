<?php

namespace App\Models;

use App\Services\SpatieRbacSyncService;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot for program ↔ supervisor staff. Keeps Spatie supervisor direct permissions in sync when the pivot changes.
 */
class ProgramSupervisor extends Pivot
{
    protected $table = 'program_supervisors';

    protected static function booted(): void
    {
        static::created(function (ProgramSupervisor $pivot): void {
            self::syncUserPermissions((int) $pivot->user_id);
        });

        static::deleted(function (ProgramSupervisor $pivot): void {
            self::syncUserPermissions((int) $pivot->user_id);
        });
    }

    private static function syncUserPermissions(int $userId): void
    {
        $user = User::query()->find($userId);
        if ($user) {
            app(SpatieRbacSyncService::class)->syncSupervisorDirectPermissions($user);
        }
    }
}
