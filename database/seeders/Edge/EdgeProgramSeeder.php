<?php

namespace Database\Seeders\Edge;

use App\Models\Process;
use App\Models\Program;
use App\Models\ProgramStationAssignment;
use App\Models\ServiceTrack;
use App\Models\Site;
use App\Models\Station;
use App\Models\TrackStep;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * AICS only (active), same structure as central. allow_public_triage=false. Per docs/seeder-plan.txt §11.
 */
class EdgeProgramSeeder extends Seeder
{
    public function run(): void
    {
        $site = Site::where('slug', 'tagudin-mswdo-field')->firstOrFail();
        $admin = User::where('site_id', $site->id)->where('role', 'admin')->firstOrFail();
        $staff = User::where('site_id', $site->id)->where('role', 'staff')->orderBy('id')->get();

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
                    'allow_public_triage' => false,
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
        $aics->supervisedBy()->syncWithoutDetaching([$staff[3]->id]);
    }
}
