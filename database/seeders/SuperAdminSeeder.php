<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates or updates a single super_admin user for platform management.
 * No super_admin exists by default; run this when you need one.
 *
 * Optional .env:
 *   SUPER_ADMIN_EMAIL=superadmin@yourdomain.com
 *   SUPER_ADMIN_PASSWORD=your-secure-password
 *
 * Defaults (if not set): superadmin@flexiqueue.local / password
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PermissionCatalogSeeder::class);

        $email = env('SUPER_ADMIN_EMAIL', 'superadmin@flexiqueue.local');
        $password = env('SUPER_ADMIN_PASSWORD', 'password');

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Super Admin',
                'password' => Hash::make($password),
                'role' => 'super_admin',
                'site_id' => null,
                'is_active' => true,
            ]
        );
    }
}
