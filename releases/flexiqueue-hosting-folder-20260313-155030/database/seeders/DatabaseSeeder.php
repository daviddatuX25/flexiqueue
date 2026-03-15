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
 * MSWDO Tagudin–realistic seeder (per MSWDO Program Seeder Specification).
 * 1 admin + 6 staff with Filipino names.
 * Programs: AICS (Regular + Priority tracks), Social Pension, Supplemental Feeding,
 *   Certificate of Indigency, Senior Citizen/OSCA ID, PWD ID, Solo Parent ID, 4Ps Inquiry.
 * Program settings (priority_first, balance_mode, allow_public_triage, etc.) set per spec.
 * Password for all: "password". Override PIN: "123456".
 * track_steps include station_id for SQLite (NOT NULL on SQLite).
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
                'settings' => [
                    'priority_first' => true,
                    'balance_mode' => 'alternate',
                    'alternate_ratio' => [2, 1],
                    'no_show_timer_seconds' => 10,
                    'require_permission_before_override' => true,
                    'station_selection_mode' => 'fixed',
                    'display_scan_timeout_seconds' => 20,
                    'display_audio_muted' => false,
                    'display_audio_volume' => 1,
                    'allow_public_triage' => true,
                ],
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

        $aicsTrackRegular = ServiceTrack::updateOrCreate(
            ['program_id' => $aics->id, 'name' => 'Regular'],
            [
                'description' => 'Standard lane',
                'is_default' => true,
                'color_code' => '#22c55e',
            ]
        );

        foreach (range(0, 3) as $i) {
            TrackStep::updateOrCreate(
                ['track_id' => $aicsTrackRegular->id, 'step_order' => $i + 1],
                [
                    'station_id' => $aicsStations[$i]->id,
                    'process_id' => $aicsProcs[$i]->id,
                    'is_required' => true,
                    'estimated_minutes' => 5,
                ]
            );
        }

        // AICS Priority track (PWD, Senior, Pregnant) — same processes/stations
        $aicsTrackPriority = ServiceTrack::updateOrCreate(
            ['program_id' => $aics->id, 'name' => 'Priority'],
            [
                'description' => 'PWD, Senior, Pregnant',
                'is_default' => false,
                'color_code' => '#eab308',
            ]
        );

        foreach (range(0, 3) as $i) {
            TrackStep::updateOrCreate(
                ['track_id' => $aicsTrackPriority->id, 'step_order' => $i + 1],
                [
                    'station_id' => $aicsStations[$i]->id,
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
                'settings' => [
                    'priority_first' => true,
                    'balance_mode' => 'fifo',
                    'no_show_timer_seconds' => 10,
                    'require_permission_before_override' => true,
                    'station_selection_mode' => 'fixed',
                    'display_scan_timeout_seconds' => 20,
                    'display_audio_muted' => false,
                    'display_audio_volume' => 1,
                    'allow_public_triage' => false,
                ],
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

        // Step 1->Window 1, Step 2->Window 2, Steps 3&4->Window 3 (Payout)
        $socpenStepStationIndex = [0, 1, 2, 2];
        foreach ([0, 1, 2, 3] as $i) {
            TrackStep::updateOrCreate(
                ['track_id' => $socpenTrack->id, 'step_order' => $i + 1],
                [
                    'station_id' => $socpenStations[$socpenStepStationIndex[$i]]->id,
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
                'settings' => [
                    'priority_first' => true,
                    'balance_mode' => 'fifo',
                    'no_show_timer_seconds' => 10,
                    'require_permission_before_override' => true,
                    'station_selection_mode' => 'fixed',
                    'display_scan_timeout_seconds' => 20,
                    'display_audio_muted' => false,
                    'display_audio_volume' => 1,
                    'allow_public_triage' => false,
                ],
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
                    'station_id' => $feedingStations[$i]->id,
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
                'description' => 'Municipal certificate for accessing indigent funds, PAO, and hospital discounts.',
                'is_active' => false,
                'created_by' => $admin->id,
                'settings' => [
                    'priority_first' => true,
                    'balance_mode' => 'fifo',
                    'no_show_timer_seconds' => 10,
                    'require_permission_before_override' => true,
                    'station_selection_mode' => 'fixed',
                    'display_scan_timeout_seconds' => 20,
                    'display_audio_muted' => false,
                    'display_audio_volume' => 1,
                    'allow_public_triage' => false,
                ],
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
                    'station_id' => $certStations[$i]->id,
                    'process_id' => $certProcs[$i]->id,
                    'is_required' => true,
                    'estimated_minutes' => 5,
                ]
            );
        }

        // --- Senior Citizen / OSCA ID and Booklet Issuance ---
        $seniorId = Program::updateOrCreate(
            ['name' => 'Senior Citizen / OSCA ID and Booklet Issuance'],
            [
                'description' => 'Issuance of Senior Citizen ID and medicine/grocery booklet (60+ years).',
                'is_active' => false,
                'created_by' => $admin->id,
                'settings' => [
                    'priority_first' => true,
                    'balance_mode' => 'fifo',
                    'no_show_timer_seconds' => 10,
                    'require_permission_before_override' => true,
                    'station_selection_mode' => 'fixed',
                    'display_scan_timeout_seconds' => 20,
                    'display_audio_muted' => false,
                    'display_audio_volume' => 1,
                    'allow_public_triage' => false,
                ],
            ]
        );

        $seniorIdProcs = [];
        foreach (
            [
                ['name' => 'Document Check', 'description' => 'Verify birth certificate, valid ID'],
                ['name' => 'Interview / Encoding', 'description' => 'Encode details, confirm eligibility'],
                ['name' => 'ID & Booklet Release', 'description' => 'Issue Senior Citizen ID and booklet'],
            ] as $p
        ) {
            $seniorIdProcs[] = Process::updateOrCreate(
                ['program_id' => $seniorId->id, 'name' => $p['name']],
                ['description' => $p['description']]
            );
        }

        $seniorIdStations = [];
        foreach (['Window 1 – Documents', 'Window 2 – Interview', 'Window 3 – Release'] as $i => $name) {
            $station = Station::updateOrCreate(
                ['program_id' => $seniorId->id, 'name' => $name],
                ['capacity' => 1, 'client_capacity' => 2, 'is_active' => true]
            );
            $station->processes()->sync([$seniorIdProcs[$i]->id]);
            $seniorIdStations[] = $station;
        }

        $seniorIdTrack = ServiceTrack::updateOrCreate(
            ['program_id' => $seniorId->id, 'name' => 'Regular'],
            [
                'description' => 'Standard lane',
                'is_default' => true,
                'color_code' => '#0ea5e9',
            ]
        );

        foreach (range(0, 2) as $i) {
            TrackStep::updateOrCreate(
                ['track_id' => $seniorIdTrack->id, 'step_order' => $i + 1],
                [
                    'station_id' => $seniorIdStations[$i]->id,
                    'process_id' => $seniorIdProcs[$i]->id,
                    'is_required' => true,
                    'estimated_minutes' => 8,
                ]
            );
        }

        foreach (range(0, 2) as $i) {
            ProgramStationAssignment::updateOrCreate(
                ['program_id' => $seniorId->id, 'user_id' => $staff[$i]->id],
                ['station_id' => $seniorIdStations[$i]->id]
            );
        }

        // --- PWD ID and Assistance ---
        $pwd = Program::updateOrCreate(
            ['name' => 'PWD ID and Assistance'],
            [
                'description' => 'PWD ID issuance and/or financial/educational assistance. Barangay cert, medical docs.',
                'is_active' => false,
                'created_by' => $admin->id,
                'settings' => [
                    'priority_first' => true,
                    'balance_mode' => 'fifo',
                    'no_show_timer_seconds' => 10,
                    'require_permission_before_override' => true,
                    'station_selection_mode' => 'fixed',
                    'display_scan_timeout_seconds' => 20,
                    'display_audio_muted' => false,
                    'display_audio_volume' => 1,
                    'allow_public_triage' => false,
                ],
            ]
        );

        $pwdProcs = [];
        foreach (
            [
                ['name' => 'Document Verification', 'description' => 'Barangay cert, PWD ID, medical/educational docs'],
                ['name' => 'Assessment', 'description' => 'Needs assessment by social worker'],
                ['name' => 'ID / Assistance Release', 'description' => 'Issue ID or disburse assistance'],
            ] as $p
        ) {
            $pwdProcs[] = Process::updateOrCreate(
                ['program_id' => $pwd->id, 'name' => $p['name']],
                ['description' => $p['description']]
            );
        }

        $pwdStations = [];
        foreach (['Verification Window', 'Assessment Window', 'Release Window'] as $i => $name) {
            $station = Station::updateOrCreate(
                ['program_id' => $pwd->id, 'name' => $name],
                ['capacity' => 1, 'client_capacity' => 2, 'is_active' => true]
            );
            $station->processes()->sync([$pwdProcs[$i]->id]);
            $pwdStations[] = $station;
        }

        $pwdTrack = ServiceTrack::updateOrCreate(
            ['program_id' => $pwd->id, 'name' => 'Regular'],
            [
                'description' => 'Standard lane',
                'is_default' => true,
                'color_code' => '#ec4899',
            ]
        );

        foreach (range(0, 2) as $i) {
            TrackStep::updateOrCreate(
                ['track_id' => $pwdTrack->id, 'step_order' => $i + 1],
                [
                    'station_id' => $pwdStations[$i]->id,
                    'process_id' => $pwdProcs[$i]->id,
                    'is_required' => true,
                    'estimated_minutes' => 8,
                ]
            );
        }

        foreach (range(0, 2) as $i) {
            ProgramStationAssignment::updateOrCreate(
                ['program_id' => $pwd->id, 'user_id' => $staff[$i + 3]->id],
                ['station_id' => $pwdStations[$i]->id]
            );
        }

        // --- Solo Parent ID Issuance ---
        $soloParent = Program::updateOrCreate(
            ['name' => 'Solo Parent ID Issuance'],
            [
                'description' => 'Issuance of Solo Parent ID for discounts and privileges under law.',
                'is_active' => false,
                'created_by' => $admin->id,
                'settings' => [
                    'priority_first' => true,
                    'balance_mode' => 'fifo',
                    'no_show_timer_seconds' => 10,
                    'require_permission_before_override' => true,
                    'station_selection_mode' => 'fixed',
                    'display_scan_timeout_seconds' => 20,
                    'display_audio_muted' => false,
                    'display_audio_volume' => 1,
                    'allow_public_triage' => false,
                ],
            ]
        );

        $soloParentProcs = [];
        foreach (
            [
                ['name' => 'Document Verification', 'description' => 'Proof of solo parent status, valid IDs'],
                ['name' => 'Interview', 'description' => 'Confirm eligibility'],
                ['name' => 'Issuance', 'description' => 'Print and release Solo Parent ID'],
            ] as $p
        ) {
            $soloParentProcs[] = Process::updateOrCreate(
                ['program_id' => $soloParent->id, 'name' => $p['name']],
                ['description' => $p['description']]
            );
        }

        $soloParentStations = [];
        foreach (['Window 1 – Documents', 'Window 2 – Interview', 'Window 3 – Issuance'] as $i => $name) {
            $station = Station::updateOrCreate(
                ['program_id' => $soloParent->id, 'name' => $name],
                ['capacity' => 1, 'client_capacity' => 2, 'is_active' => true]
            );
            $station->processes()->sync([$soloParentProcs[$i]->id]);
            $soloParentStations[] = $station;
        }

        $soloParentTrack = ServiceTrack::updateOrCreate(
            ['program_id' => $soloParent->id, 'name' => 'Regular'],
            [
                'description' => 'Standard lane',
                'is_default' => true,
                'color_code' => '#14b8a6',
            ]
        );

        foreach (range(0, 2) as $i) {
            TrackStep::updateOrCreate(
                ['track_id' => $soloParentTrack->id, 'step_order' => $i + 1],
                [
                    'station_id' => $soloParentStations[$i]->id,
                    'process_id' => $soloParentProcs[$i]->id,
                    'is_required' => true,
                    'estimated_minutes' => 5,
                ]
            );
        }

        foreach (range(0, 2) as $i) {
            ProgramStationAssignment::updateOrCreate(
                ['program_id' => $soloParent->id, 'user_id' => $staff[$i]->id],
                ['station_id' => $soloParentStations[$i]->id]
            );
        }

        // --- 4Ps (Pantawid Pamilyang Pilipino Program) – LGU Inquiry / Orientation ---
        $fourPs = Program::updateOrCreate(
            ['name' => '4Ps Inquiry / Orientation'],
            [
                'description' => '4Ps inquiries, Listahanan status, grievance, or beneficiary orientation at LGU.',
                'is_active' => false,
                'created_by' => $admin->id,
                'settings' => [
                    'priority_first' => true,
                    'balance_mode' => 'fifo',
                    'no_show_timer_seconds' => 10,
                    'require_permission_before_override' => true,
                    'station_selection_mode' => 'fixed',
                    'display_scan_timeout_seconds' => 20,
                    'display_audio_muted' => false,
                    'display_audio_volume' => 1,
                    'allow_public_triage' => false,
                ],
            ]
        );

        $fourPsProcs = [];
        foreach (
            [
                ['name' => 'Inquiry / Reception', 'description' => 'Receive client, check concern'],
                ['name' => 'Assessment or Orientation', 'description' => 'Explain 4Ps or assess grievance'],
                ['name' => 'Referral / Encoding', 'description' => 'Refer to DSWD or encode as needed'],
            ] as $p
        ) {
            $fourPsProcs[] = Process::updateOrCreate(
                ['program_id' => $fourPs->id, 'name' => $p['name']],
                ['description' => $p['description']]
            );
        }

        $fourPsStations = [];
        foreach (['Inquiry Window', 'Orientation Area', 'Release/Referral Window'] as $i => $name) {
            $station = Station::updateOrCreate(
                ['program_id' => $fourPs->id, 'name' => $name],
                ['capacity' => 1, 'client_capacity' => 2, 'is_active' => true]
            );
            $station->processes()->sync([$fourPsProcs[$i]->id]);
            $fourPsStations[] = $station;
        }

        $fourPsTrack = ServiceTrack::updateOrCreate(
            ['program_id' => $fourPs->id, 'name' => 'Regular'],
            [
                'description' => 'Standard lane',
                'is_default' => true,
                'color_code' => '#84cc16',
            ]
        );

        foreach (range(0, 2) as $i) {
            TrackStep::updateOrCreate(
                ['track_id' => $fourPsTrack->id, 'step_order' => $i + 1],
                [
                    'station_id' => $fourPsStations[$i]->id,
                    'process_id' => $fourPsProcs[$i]->id,
                    'is_required' => true,
                    'estimated_minutes' => 10,
                ]
            );
        }

        foreach (range(0, 2) as $i) {
            ProgramStationAssignment::updateOrCreate(
                ['program_id' => $fourPs->id, 'user_id' => $staff[$i + 3]->id],
                ['station_id' => $fourPsStations[$i]->id]
            );
        }

        // AICS is active by default; staff assigned_station_id reflects their AICS station
        // (ProgramService::syncAssignedStationId runs on activate; AICS is already active)
    }
}
