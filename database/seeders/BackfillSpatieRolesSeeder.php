<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\RbacTeam;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Backfill Spatie roles for users who may have lost them during the migration
 * to drop the legacy users.role column.
 *
 * This seeder runs the role assignment logic that should have happened in
 * 2026_03_22_210000_drop_users_role_column_after_spatie_backfill but may have
 * been incomplete due to database state or Spatie role creation timing.
 */
class BackfillSpatieRolesSeeder extends Seeder
{
    public function run(): void
    {
        // Get all users and ensure they have Spatie roles assigned
        $users = User::all();

        foreach ($users as $user) {
            // Check if user already has a role
            if ($user->primaryGlobalRoleName() !== null) {
                $this->command->info("User {$user->name} ({$user->id}) already has role: {$user->primaryGlobalRoleName()}");
                continue;
            }

            // Assign role based on site/admin status
            // Super admin users (site_id = null) typically get super_admin
            // Site admins get admin
            // Others get staff
            $roleToAssign = $this->determineRole($user);

            User::assignGlobalRoleAndSyncProvisioning($user, $roleToAssign);
            $this->command->info("Assigned {$roleToAssign} role to user {$user->name} ({$user->id})");
        }
    }

    private function determineRole(User $user): string
    {
        // If no site_id, likely a super admin
        if ($user->site_id === null) {
            return UserRole::SuperAdmin->value;
        }

        // If this is the first/only admin for a site, they're likely an admin
        // For now, assume site-assigned users without roles should be staff
        // This is conservative - manually promote users who should be admins
        return UserRole::Staff->value;
    }
}
