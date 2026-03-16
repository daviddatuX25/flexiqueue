<?php

namespace Database\Seeders\Edge;

use App\Enums\UserRole;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates 1 admin + 4 staff for edge. Per docs/seeder-plan.txt §10.
 */
class EdgeUsersSeeder extends Seeder
{
    public function run(): void
    {
        $site = Site::where('slug', 'tagudin-mswdo-field')->firstOrFail();
        $password = Hash::make('password');
        $overridePin = Hash::make('123456');

        $users = [
            ['role' => UserRole::Admin, 'name' => 'Lourdes Valdez', 'email' => 'admin@tagudinfield.gov.ph'],
            ['role' => UserRole::Staff, 'name' => 'Maria Santos', 'email' => 'staff1@tagudinfield.gov.ph'],
            ['role' => UserRole::Staff, 'name' => 'Juan Dela Cruz', 'email' => 'staff2@tagudinfield.gov.ph'],
            ['role' => UserRole::Staff, 'name' => 'Rosa Reyes', 'email' => 'staff3@tagudinfield.gov.ph'],
            ['role' => UserRole::Staff, 'name' => 'Jose Garcia', 'email' => 'staff4@tagudinfield.gov.ph'],
        ];

        foreach ($users as $u) {
            User::updateOrCreate(
                ['email' => $u['email']],
                [
                    'name' => $u['name'],
                    'password' => $password,
                    'role' => $u['role'],
                    'site_id' => $site->id,
                    'is_active' => true,
                    'availability_status' => 'available',
                    'override_pin' => $overridePin,
                    'override_qr_token' => Hash::make(Str::random(64)),
                ]
            );
        }
    }
}
