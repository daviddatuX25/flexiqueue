<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Process;
use App\Models\Program;
use App\Models\ProgramStationAssignment;
use App\Models\ServiceTrack;
use App\Models\Station;
use App\Models\TrackStep;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * MSWDO Tagudin–realistic seeder.
 * 1 admin + 6 staff with Filipino names.
 * Programs: AICS, Social Pension, Supplemental Feeding, Certificate of Indigency.
 * Password for all: "password". Override PIN: "123456".
 *
 * Login credentials:
 *   Admin:  admin@tagudinmswdo.gov.ph / password
 *   Staff:  staff1@tagudinmswdo.gov.ph … staff6@tagudinmswdo.gov.ph / password
 *   Names:  Lourdes Valdez (admin); Maria Santos, Juan Dela Cruz, Rosa Reyes,
 *           Jose Garcia, Ana Flores, Pedro Villanueva (staff)
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $password = Hash::make('password');
        $defaultPin = Hash::make('123456');

        // 1 Admin
        $admin = User::updateOrCreate(
            ['email' => 'admin@tagudinmswdo.gov.ph'],
            [
                'name' => 'Lourdes Valdez',
                'password' => $password,
                'role' => UserRole::Admin,
                'is_active' => true,
                'override_pin' => $defaultPin,
                'override_qr_token' => Hash::make(Str::random(64)),
            ]
        );

        // 6 Staff with Filipino names (Ilocano/common surnames)
        $staffNames = [
            'Maria Santos',
            'Juan Dela Cruz',
            'Rosa Reyes',
            'Jose Garcia',
            'Ana Flores',
            'Pedro Villanueva',
        ];

        $staff = [];
        foreach ($staffNames as $i => $name) {
            $staff[] = User::updateOrCreate(
                ['email' => 'staff' . ($i + 1) . '@tagudinmswdo.gov.ph'],
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

        // --- AICS (Assistance to Individuals in Crisis Situation) ---
        $aics = Program::updateOrCreate(
            ['name' => 'Assistance to Individuals in Crisis Situation (AICS)'],
            [
                'description' => 'Financial and material assistance for medical, educational, burial, transport, and food needs. DSWD devolved program.',
                'is_active' => true,
                'created_by' => $admin->id,
            ]
        );

        $aicsProcesses = [
            ['name' => 'Screening', 'description' => 'Document verification, ID check'],
            ['name' => 'Interview & Assessment', 'description' => 'Needs assessment by social worker'],
            ['name' => 'Verification', 'description' => 'Validation against records'],
            ['name' => 'Cash Release', 'description' => 'Assistance disbursement'],
        ];

        $aicsProcs = [];
        foreach ($aicsProcesses as $p) {
            $aicsProcs[] = Process::updateOrCreate(
                ['program_id' => $aics->id, 'name' => $p['name']],
                ['description' => $p['description']]
            );
        }

        $aicsStations = [];
        $aicsStationNames = ['Window 1 – Screening', 'Window 2 – Interview', 'Window 3 – Verification', 'Window 4 – Cash Release'];
        foreach ($aicsStationNames as $i => $name) {
            $station = Station::updateOrCreate(
                ['program_id' => $aics->id, 'name' => $name],
                ['capacity' => 1, 'client_capacity' => 2, 'is_active' => true]
            );
            $station->processes()->sync([$aicsProcs[$i]->id]);
            $aicsStations[] = $station;
        }

        $aicsTrack = ServiceTrack::updateOrCreate(
            ['program_id' => $aics->id, 'name' => 'Regular'],
            [
                'description' => 'Standard lane',
                'is_default' => true,
                'color_code' => '#22c55e',
            ]
        );

        foreach (range(0, 3) as $i) {
            TrackStep::updateOrCreate(
                ['track_id' => $aicsTrack->id, 'step_order' => $i + 1],
                [
                    'process_id' => $aicsProcs[$i]->id,
                    'is_required' => true,
                    'estimated_minutes' => 5,
                ]
            );
        }

        // Assign staff to AICS stations
        foreach (range(0, 3) as $i) {
            ProgramStationAssignment::updateOrCreate(
                ['program_id' => $aics->id, 'user_id' => $staff[$i]->id],
                ['station_id' => $aicsStations[$i]->id]
            );
            $staff[$i]->update(['assigned_station_id' => $aicsStations[$i]->id]);
        }

        // Pedro Villanueva = supervisor for AICS
        $aics->supervisedBy()->syncWithoutDetaching([$staff[5]->id]);

        // --- Social Pension for Indigent Senior Citizens ---
        $socpen = Program::updateOrCreate(
            ['name' => 'Social Pension for Indigent Senior Citizens'],
            [
                'description' => 'Monthly pension for qualified indigent senior citizens. DSWD devolved program.',
                'is_active' => false,
                'created_by' => $admin->id,
            ]
        );

        $socpenProcs = [];
        foreach (
            [
                ['name' => 'Eligibility Check', 'description' => 'Senior citizen ID, birth certificate verification'],
                ['name' => 'Interview', 'description' => 'Indigent status confirmation'],
                ['name' => 'Verification', 'description' => 'Cross-check with beneficiary list'],
                ['name' => 'Payout', 'description' => 'Pension disbursement'],
            ] as $p
        ) {
            $socpenProcs[] = Process::updateOrCreate(
                ['program_id' => $socpen->id, 'name' => $p['name']],
                ['description' => $p['description']]
            );
        }

        $socpenStations = [];
        foreach (['Window 1 – Eligibility', 'Window 2 – Interview', 'Window 3 – Payout'] as $i => $name) {
            $procIds = $i === 1
                ? [$socpenProcs[1]->id]
                : ($i === 0 ? [$socpenProcs[0]->id] : [$socpenProcs[2]->id, $socpenProcs[3]->id]);
            $station = Station::updateOrCreate(
                ['program_id' => $socpen->id, 'name' => $name],
                ['capacity' => 1, 'client_capacity' => 2, 'is_active' => true]
            );
            $station->processes()->sync($procIds);
            $socpenStations[] = $station;
        }

        $socpenTrack = ServiceTrack::updateOrCreate(
            ['program_id' => $socpen->id, 'name' => 'Regular'],
            [
                'description' => 'Standard lane',
                'is_default' => true,
                'color_code' => '#3b82f6',
            ]
        );

        foreach ([0, 1, 2, 3] as $i) {
            TrackStep::updateOrCreate(
                ['track_id' => $socpenTrack->id, 'step_order' => $i + 1],
                [
                    'process_id' => $socpenProcs[$i]->id,
                    'is_required' => true,
                    'estimated_minutes' => 5,
                ]
            );
        }

        // Ana Flores, Pedro Villanueva on Social Pension
        ProgramStationAssignment::updateOrCreate(
            ['program_id' => $socpen->id, 'user_id' => $staff[4]->id],
            ['station_id' => $socpenStations[0]->id]
        );
        ProgramStationAssignment::updateOrCreate(
            ['program_id' => $socpen->id, 'user_id' => $staff[5]->id],
            ['station_id' => $socpenStations[1]->id]
        );

        // --- Supplemental Feeding Program ---
        $feeding = Program::updateOrCreate(
            ['name' => 'Supplemental Feeding Program'],
            [
                'description' => 'Daily feeding for undernourished children. DSWD devolved program.',
                'is_active' => false,
                'created_by' => $admin->id,
            ]
        );

        $feedingProcs = [];
        foreach (
            [
                ['name' => 'Registration', 'description' => 'Child/guardian enrolment'],
                ['name' => 'Nutritional Assessment', 'description' => 'Height, weight, screening'],
                ['name' => 'Feeding', 'description' => 'Daily meal/ration distribution'],
            ] as $p
        ) {
            $feedingProcs[] = Process::updateOrCreate(
                ['program_id' => $feeding->id, 'name' => $p['name']],
                ['description' => $p['description']]
            );
        }

        $feedingStations = [];
        foreach (['Registration Desk', 'Assessment Area', 'Feeding Station'] as $i => $name) {
            $station = Station::updateOrCreate(
                ['program_id' => $feeding->id, 'name' => $name],
                ['capacity' => 1, 'client_capacity' => 3, 'is_active' => true]
            );
            $station->processes()->sync([$feedingProcs[$i]->id]);
            $feedingStations[] = $station;
        }

        $feedingTrack = ServiceTrack::updateOrCreate(
            ['program_id' => $feeding->id, 'name' => 'Regular'],
            [
                'description' => 'Standard lane',
                'is_default' => true,
                'color_code' => '#f59e0b',
            ]
        );

        foreach (range(0, 2) as $i) {
            TrackStep::updateOrCreate(
                ['track_id' => $feedingTrack->id, 'step_order' => $i + 1],
                [
                    'process_id' => $feedingProcs[$i]->id,
                    'is_required' => true,
                    'estimated_minutes' => 10,
                ]
            );
        }

        // --- Certificate of Indigency ---
        $cert = Program::updateOrCreate(
            ['name' => 'Certificate of Indigency'],
            [
                'description' => 'Municipal certificate for accessing indigent funds and medical assistance.',
                'is_active' => false,
                'created_by' => $admin->id,
            ]
        );

        $certProcs = [];
        foreach (
            [
                ['name' => 'Document Verification', 'description' => 'Barangay certificate, valid IDs'],
                ['name' => 'Interview', 'description' => 'Indigent status verification'],
                ['name' => 'Issuance', 'description' => 'Certificate printing and release'],
            ] as $p
        ) {
            $certProcs[] = Process::updateOrCreate(
                ['program_id' => $cert->id, 'name' => $p['name']],
                ['description' => $p['description']]
            );
        }

        $certStations = [];
        foreach (['Verification Window', 'Interview Window', 'Issuance Window'] as $i => $name) {
            $station = Station::updateOrCreate(
                ['program_id' => $cert->id, 'name' => $name],
                ['capacity' => 1, 'client_capacity' => 2, 'is_active' => true]
            );
            $station->processes()->sync([$certProcs[$i]->id]);
            $certStations[] = $station;
        }

        $certTrack = ServiceTrack::updateOrCreate(
            ['program_id' => $cert->id, 'name' => 'Regular'],
            [
                'description' => 'Standard lane',
                'is_default' => true,
                'color_code' => '#8b5cf6',
            ]
        );

        foreach (range(0, 2) as $i) {
            TrackStep::updateOrCreate(
                ['track_id' => $certTrack->id, 'step_order' => $i + 1],
                [
                    'process_id' => $certProcs[$i]->id,
                    'is_required' => true,
                    'estimated_minutes' => 5,
                ]
            );
        }

        // --- E2E Test Program (backward compatibility) ---
        $e2e = Program::updateOrCreate(
            ['name' => 'E2E Test Program'],
            [
                'description' => 'Minimal program for E2E tests (manage steps, etc.)',
                'is_active' => false,
                'created_by' => $admin->id,
            ]
        );

        $e2eProcs = [];
        foreach (['Verification', 'Interview'] as $name) {
            $e2eProcs[] = Process::updateOrCreate(
                ['program_id' => $e2e->id, 'name' => $name],
                ['description' => null]
            );
        }

        $e2eS1 = Station::updateOrCreate(
            ['program_id' => $e2e->id, 'name' => 'Verification'],
            ['capacity' => 1, 'client_capacity' => 1, 'is_active' => true]
        );
        $e2eS1->processes()->sync([$e2eProcs[0]->id]);

        $e2eS2 = Station::updateOrCreate(
            ['program_id' => $e2e->id, 'name' => 'Interview'],
            ['capacity' => 1, 'client_capacity' => 1, 'is_active' => true]
        );
        $e2eS2->processes()->sync([$e2eProcs[1]->id]);

        $e2eTrack = ServiceTrack::updateOrCreate(
            ['program_id' => $e2e->id, 'name' => 'Regular'],
            [
                'description' => 'Regular lane',
                'is_default' => true,
                'color_code' => '#22c55e',
            ]
        );

        TrackStep::updateOrCreate(
            ['track_id' => $e2eTrack->id, 'step_order' => 1],
            ['process_id' => $e2eProcs[0]->id, 'is_required' => true]
        );
        TrackStep::updateOrCreate(
            ['track_id' => $e2eTrack->id, 'step_order' => 2],
            ['process_id' => $e2eProcs[1]->id, 'is_required' => true]
        );

        ProgramStationAssignment::updateOrCreate(
            ['program_id' => $e2e->id, 'user_id' => $staff[0]->id],
            ['station_id' => $e2eS1->id]
        );
        ProgramStationAssignment::updateOrCreate(
            ['program_id' => $e2e->id, 'user_id' => $staff[1]->id],
            ['station_id' => $e2eS2->id]
        );
        $e2e->supervisedBy()->syncWithoutDetaching([$staff[5]->id]);

        // AICS is active by default; staff assigned_station_id reflects their AICS station
        // (ProgramService::syncAssignedStationId runs on activate; AICS is already active)
    }
}
