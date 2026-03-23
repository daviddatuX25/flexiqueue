<?php

namespace Database\Seeders\Central;

use App\Enums\UserRole;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates 1 admin + 6 staff per site. Per docs/seeder-plan.txt §4.
 */
class CentralUsersSeeder extends Seeder
{
    public function run(): void
    {
        $password = Hash::make('password');
        $overridePin = Hash::make('123456');

        $tagudin = Site::where('slug', 'tagudin-mswdo')->firstOrFail();
        $candon = Site::where('slug', 'candon-mswdo')->firstOrFail();

        $tagudinUsers = [
            ['role' => UserRole::Admin, 'name' => 'Lourdes Valdez', 'email' => 'admin@tagudinmswdo.gov.ph', 'username' => 'admin.tagudin'],
            ['role' => UserRole::Staff, 'name' => 'Maria Santos', 'email' => 'staff1@tagudinmswdo.gov.ph', 'username' => 'staff1.tagudin'],
            ['role' => UserRole::Staff, 'name' => 'Juan Dela Cruz', 'email' => 'staff2@tagudinmswdo.gov.ph', 'username' => 'staff2.tagudin'],
            ['role' => UserRole::Staff, 'name' => 'Rosa Reyes', 'email' => 'staff3@tagudinmswdo.gov.ph', 'username' => 'staff3.tagudin'],
            ['role' => UserRole::Staff, 'name' => 'Jose Garcia', 'email' => 'staff4@tagudinmswdo.gov.ph', 'username' => 'staff4.tagudin'],
            ['role' => UserRole::Staff, 'name' => 'Ana Flores', 'email' => 'staff5@tagudinmswdo.gov.ph', 'username' => 'staff5.tagudin'],
            ['role' => UserRole::Staff, 'name' => 'Pedro Villanueva', 'email' => 'staff6@tagudinmswdo.gov.ph', 'username' => 'staff6.tagudin'],
        ];

        foreach ($tagudinUsers as $u) {
            $row = User::updateOrCreate(
                ['email' => $u['email']],
                [
                    'name' => $u['name'],
                    'username' => $u['username'],
                    'recovery_gmail' => $u['email'],
                    'password' => $password,
                    'site_id' => $tagudin->id,
                    'is_active' => true,
                    'availability_status' => User::AVAILABILITY_AWAY,
                    'override_pin' => $overridePin,
                    'override_qr_token' => Hash::make(Str::random(64)),
                ]
            );
            User::assignGlobalRoleAndSyncProvisioning($row, $u['role']->value);
        }

        $candonUsers = [
            ['role' => UserRole::Admin, 'name' => 'Carmen Aquino', 'email' => 'admin@candonmswdo.gov.ph', 'username' => 'admin.candon'],
            ['role' => UserRole::Staff, 'name' => 'Elena Bautista', 'email' => 'staff1@candonmswdo.gov.ph', 'username' => 'staff1.candon'],
            ['role' => UserRole::Staff, 'name' => 'Ramon Castillo', 'email' => 'staff2@candonmswdo.gov.ph', 'username' => 'staff2.candon'],
            ['role' => UserRole::Staff, 'name' => 'Nora Espiritu', 'email' => 'staff3@candonmswdo.gov.ph', 'username' => 'staff3.candon'],
            ['role' => UserRole::Staff, 'name' => 'Dante Hernandez', 'email' => 'staff4@candonmswdo.gov.ph', 'username' => 'staff4.candon'],
            ['role' => UserRole::Staff, 'name' => 'Celia Ignacio', 'email' => 'staff5@candonmswdo.gov.ph', 'username' => 'staff5.candon'],
            ['role' => UserRole::Staff, 'name' => 'Felix Jacinto', 'email' => 'staff6@candonmswdo.gov.ph', 'username' => 'staff6.candon'],
        ];

        foreach ($candonUsers as $u) {
            $row = User::updateOrCreate(
                ['email' => $u['email']],
                [
                    'name' => $u['name'],
                    'username' => $u['username'],
                    'recovery_gmail' => $u['email'],
                    'password' => $password,
                    'site_id' => $candon->id,
                    'is_active' => true,
                    'availability_status' => User::AVAILABILITY_AWAY,
                    'override_pin' => $overridePin,
                    'override_qr_token' => Hash::make(Str::random(64)),
                ]
            );
            User::assignGlobalRoleAndSyncProvisioning($row, $u['role']->value);
        }
    }
}
