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
            ['role' => UserRole::Admin, 'name' => 'Lourdes Valdez', 'email' => 'admin@tagudinmswdo.gov.ph'],
            ['role' => UserRole::Staff, 'name' => 'Maria Santos', 'email' => 'staff1@tagudinmswdo.gov.ph'],
            ['role' => UserRole::Staff, 'name' => 'Juan Dela Cruz', 'email' => 'staff2@tagudinmswdo.gov.ph'],
            ['role' => UserRole::Staff, 'name' => 'Rosa Reyes', 'email' => 'staff3@tagudinmswdo.gov.ph'],
            ['role' => UserRole::Staff, 'name' => 'Jose Garcia', 'email' => 'staff4@tagudinmswdo.gov.ph'],
            ['role' => UserRole::Staff, 'name' => 'Ana Flores', 'email' => 'staff5@tagudinmswdo.gov.ph'],
            ['role' => UserRole::Staff, 'name' => 'Pedro Villanueva', 'email' => 'staff6@tagudinmswdo.gov.ph'],
        ];

        foreach ($tagudinUsers as $u) {
            User::updateOrCreate(
                ['email' => $u['email']],
                [
                    'name' => $u['name'],
                    'password' => $password,
                    'role' => $u['role'],
                    'site_id' => $tagudin->id,
                    'is_active' => true,
                    'availability_status' => 'available',
                    'override_pin' => $overridePin,
                    'override_qr_token' => Hash::make(Str::random(64)),
                ]
            );
        }

        $candonUsers = [
            ['role' => UserRole::Admin, 'name' => 'Carmen Aquino', 'email' => 'admin@candonmswdo.gov.ph'],
            ['role' => UserRole::Staff, 'name' => 'Elena Bautista', 'email' => 'staff1@candonmswdo.gov.ph'],
            ['role' => UserRole::Staff, 'name' => 'Ramon Castillo', 'email' => 'staff2@candonmswdo.gov.ph'],
            ['role' => UserRole::Staff, 'name' => 'Nora Espiritu', 'email' => 'staff3@candonmswdo.gov.ph'],
            ['role' => UserRole::Staff, 'name' => 'Dante Hernandez', 'email' => 'staff4@candonmswdo.gov.ph'],
            ['role' => UserRole::Staff, 'name' => 'Celia Ignacio', 'email' => 'staff5@candonmswdo.gov.ph'],
            ['role' => UserRole::Staff, 'name' => 'Felix Jacinto', 'email' => 'staff6@candonmswdo.gov.ph'],
        ];

        foreach ($candonUsers as $u) {
            User::updateOrCreate(
                ['email' => $u['email']],
                [
                    'name' => $u['name'],
                    'password' => $password,
                    'role' => $u['role'],
                    'site_id' => $candon->id,
                    'is_active' => true,
                    'availability_status' => 'available',
                    'override_pin' => $overridePin,
                    'override_qr_token' => Hash::make(Str::random(64)),
                ]
            );
        }
    }
}
