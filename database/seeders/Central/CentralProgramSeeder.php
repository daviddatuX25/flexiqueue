<?php

namespace Database\Seeders\Central;

use App\Enums\UserRole;
use App\Models\Process;
use App\Models\Program;
use App\Models\ProgramStationAssignment;
use App\Models\ServiceTrack;
use App\Models\Site;
use App\Models\Station;
use App\Models\TrackStep;
use App\Models\User;
use App\Services\ProgramSupervisorGrantService;
use App\Services\SpatieRbacSyncService;
use Illuminate\Database\Seeder;

/**
 * Creates 3 programs per site: AICS (active), Social Pension (inactive), PWD ID (inactive).
 * Per docs/seeder-plan.txt §5.
 */
class CentralProgramSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['tagudin-mswdo', 'candon-mswdo'] as $slug) {
            $site = Site::where('slug', $slug)->firstOrFail();
            $admin = User::withGlobalPermissionsTeam(fn () => User::query()
                ->where('site_id', $site->id)
                ->role(UserRole::Admin->value)
                ->firstOrFail());
            $staff = User::withGlobalPermissionsTeam(fn () => User::query()
                ->where('site_id', $site->id)
                ->role(UserRole::Staff->value)
                ->orderBy('id')
                ->get());
            $this->seedProgramsForSite($site, $admin, $staff);
        }
    }

    private function seedProgramsForSite(Site $site, User $admin, $staff): void
    {
        // --- AICS (active) ---
        $aics = Program::updateOrCreate(
            ['site_id' => $site->id, 'name' => 'Assistance to Individuals in Crisis Situation (AICS)'],
            [
                'description' => 'Financial and material assistance for medical, educational, burial, transport, and food needs.',
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

        $aicsStationNames = ['Window 1 – Screening', 'Window 2 – Interview', 'Window 3 – Verification', 'Window 4 – Cash Release'];
        $aicsStations = [];
        foreach ($aicsStationNames as $i => $name) {
            $station = Station::updateOrCreate(
                ['program_id' => $aics->id, 'name' => $name],
                ['capacity' => 1, 'client_capacity' => 2, 'is_active' => true]
            );
            $station->processes()->sync([$aicsProcs[$i]->id]);
            $aicsStations[] = $station;
        }

        $regular = ServiceTrack::updateOrCreate(
            ['program_id' => $aics->id, 'name' => 'Regular'],
            ['description' => 'Standard lane', 'is_default' => true, 'color_code' => '#22c55e']
        );
        $priority = ServiceTrack::updateOrCreate(
            ['program_id' => $aics->id, 'name' => 'Priority'],
            ['description' => 'PWD, Senior, Pregnant', 'is_default' => false, 'color_code' => '#eab308']
        );
        foreach ([$regular, $priority] as $track) {
            for ($i = 0; $i < 4; $i++) {
                TrackStep::updateOrCreate(
                    ['track_id' => $track->id, 'step_order' => $i + 1],
                    [
                        'station_id' => $aicsStations[$i]->id,
                        'process_id' => $aicsProcs[$i]->id,
                        'is_required' => true,
                        'estimated_minutes' => 5,
                    ]
                );
            }
        }

        for ($i = 0; $i < 4; $i++) {
            ProgramStationAssignment::updateOrCreate(
                ['program_id' => $aics->id, 'user_id' => $staff[$i]->id],
                ['station_id' => $aicsStations[$i]->id]
            );
            $staff[$i]->update(['assigned_station_id' => $aicsStations[$i]->id]);
        }
        app(ProgramSupervisorGrantService::class)->grantProgramTeamSupervise($staff[5], $aics);
        app(SpatieRbacSyncService::class)->syncSupervisorDirectPermissions($staff[5]->fresh());

        // --- Social Pension (inactive) ---
        $socpen = Program::updateOrCreate(
            ['site_id' => $site->id, 'name' => 'Social Pension for Indigent Senior Citizens'],
            [
                'description' => 'Monthly pension for qualified indigent senior citizens.',
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
            $station = Station::updateOrCreate(
                ['program_id' => $socpen->id, 'name' => $name],
                ['capacity' => 1, 'client_capacity' => 2, 'is_active' => true]
            );
            $station->processes()->sync([$socpenProcs[$i]->id]);
            $socpenStations[] = $station;
        }
        $socpenTrack = ServiceTrack::updateOrCreate(
            ['program_id' => $socpen->id, 'name' => 'Regular'],
            ['description' => 'Standard lane', 'is_default' => true, 'color_code' => '#3b82f6']
        );
        for ($i = 0; $i < 3; $i++) {
            TrackStep::updateOrCreate(
                ['track_id' => $socpenTrack->id, 'step_order' => $i + 1],
                [
                    'station_id' => $socpenStations[$i]->id,
                    'process_id' => $socpenProcs[$i]->id,
                    'is_required' => true,
                    'estimated_minutes' => 5,
                ]
            );
        }

        // --- PWD ID and Assistance (inactive) ---
        $pwd = Program::updateOrCreate(
            ['site_id' => $site->id, 'name' => 'PWD ID and Assistance'],
            [
                'description' => 'PWD ID issuance and/or financial/educational assistance.',
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
                ['name' => 'Document Verification', 'description' => 'Barangay cert, PWD ID, medical docs'],
                ['name' => 'Assessment', 'description' => 'Needs assessment by social worker'],
                ['name' => 'ID Release', 'description' => 'Issue ID or disburse assistance'],
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
            ['description' => 'Standard lane', 'is_default' => true, 'color_code' => '#ec4899']
        );
        for ($i = 0; $i < 3; $i++) {
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
    }
}
