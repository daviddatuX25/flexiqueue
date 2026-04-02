<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StaffActivityLogService
{
    /**
     * Log a staff activity event to staff_activity_log.
     * Guards against the table not existing (e.g. during migrations or fresh installs).
     */
    public function logActivity(
        int $userId,
        string $actionType,
        string $oldValue,
        string $newValue,
        ?array $metadata = null
    ): void {
        if (! Schema::hasTable('staff_activity_log')) {
            return;
        }

        DB::table('staff_activity_log')->insert([
            'user_id'     => $userId,
            'action_type' => $actionType,
            'old_value'   => $oldValue,
            'new_value'   => $newValue,
            'metadata'    => $metadata !== null ? json_encode($metadata) : null,
            'created_at'  => now(),
        ]);
    }
}
