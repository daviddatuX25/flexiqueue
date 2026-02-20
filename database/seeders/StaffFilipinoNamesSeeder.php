<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Temporary seeder: 6 staff users with Filipino names.
 * Password: "password", override PIN: "123456".
 * Run: php artisan db:seed --class=StaffFilipinoNamesSeeder
 */
class StaffFilipinoNamesSeeder extends Seeder
{
    public function run(): void
    {
        $password = Hash::make('password');
        $defaultPin = Hash::make('123456');

        $names = [
            'Maria Santos',
            'Juan Dela Cruz',
            'Rosa Reyes',
            'Jose Garcia',
            'Ana Flores',
            'Pedro Villanueva',
        ];

        foreach ($names as $i => $name) {
            $email = 'staff' . ($i + 1) . '@example.com';
            User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => $password,
                    'role' => UserRole::Staff,
                    'is_active' => true,
                    'override_pin' => $defaultPin,
                    'override_qr_token' => Hash::make(Str::random(64)),
                ]
            );
        }
    }
}
