<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Program;
use App\Models\ServiceTrack;
use App\Models\Station;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed users for login testing. Password for all: "password".
     * Also seeds one program with a track and two stations for E2E (e.g. manage steps).
     */
    public function run(): void
    {
        $password = Hash::make('password');

        $admin = User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin User',
                'password' => $password,
                'role' => UserRole::Admin,
                'is_active' => true,
            ]
        );

        $staff = User::updateOrCreate(
            ['email' => 'staff@example.com'],
            [
                'name' => 'Staff User',
                'password' => $password,
                'role' => UserRole::Staff,
                'is_active' => true,
            ]
        );

        User::updateOrCreate(
            ['email' => 'supervisor@example.com'],
            [
                'name' => 'Supervisor User',
                'password' => $password,
                'role' => UserRole::Supervisor,
                'is_active' => true,
            ]
        );

        $program = Program::updateOrCreate(
            ['name' => 'E2E Test Program'],
            [
                'description' => 'For E2E tests (manage steps, etc.)',
                'is_active' => false,
                'created_by' => $admin->id,
            ]
        );

        $track = ServiceTrack::updateOrCreate(
            ['program_id' => $program->id, 'name' => 'Regular'],
            [
                'description' => 'Regular lane',
                'is_default' => true,
                'color_code' => '#22c55e',
            ]
        );

        $s1 = Station::updateOrCreate(
            ['program_id' => $program->id, 'name' => 'Verification'],
            ['capacity' => 1, 'is_active' => true]
        );
        $s2 = Station::updateOrCreate(
            ['program_id' => $program->id, 'name' => 'Interview'],
            ['capacity' => 1, 'is_active' => true]
        );
        Station::updateOrCreate(
            ['program_id' => $program->id, 'name' => 'Cashier'],
            ['capacity' => 1, 'is_active' => true]
        );

        $track->trackSteps()->firstOrCreate(
            ['track_id' => $track->id, 'step_order' => 1],
            ['station_id' => $s1->id, 'is_required' => true]
        );
        $track->trackSteps()->firstOrCreate(
            ['track_id' => $track->id, 'step_order' => 2],
            ['station_id' => $s2->id, 'is_required' => true]
        );

        $staff->update(['assigned_station_id' => $s1->id]);
        $program->update(['is_active' => true]);
    }
}
