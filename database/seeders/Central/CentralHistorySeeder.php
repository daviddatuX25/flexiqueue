<?php

namespace Database\Seeders\Central;

use App\Models\Program;
use App\Models\Site;
use App\Models\Station;
use App\Models\Token;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\Shared\HistoryHelper;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds 10 days of queue history per site for analytics. Per docs/seeder-plan.txt §8.
 */
class CentralHistorySeeder extends Seeder
{
    /** @var array<int, array{tagudin: int, candon: int}> day offset => volumes */
    private const DAILY_VOLUMES = [
        -10 => ['tagudin' => 28, 'candon' => 22],
        -9 => ['tagudin' => 35, 'candon' => 30],
        -8 => ['tagudin' => 41, 'candon' => 38],
        -7 => ['tagudin' => 32, 'candon' => 27],
        -6 => ['tagudin' => 20, 'candon' => 18],
        -5 => ['tagudin' => 8, 'candon' => 6],
        -4 => ['tagudin' => 0, 'candon' => 0],
        -3 => ['tagudin' => 31, 'candon' => 25],
        -2 => ['tagudin' => 38, 'candon' => 33],
        -1 => ['tagudin' => 44, 'candon' => 40],
    ];

    public function run(): void
    {
        if (DB::table('queue_sessions')->count() > 0) {
            $this->command->info('History already exists, skipping CentralHistorySeeder.');

            return;
        }

        foreach (['tagudin-mswdo', 'candon-mswdo'] as $siteSlug) {
            $volumeKey = $siteSlug === 'tagudin-mswdo' ? 'tagudin' : 'candon';
            $site = Site::where('slug', $siteSlug)->firstOrFail();
            $aics = Program::where('site_id', $site->id)->where('name', 'like', '%AICS%')->firstOrFail();
            $tracks = $aics->serviceTracks()->get()->keyBy('name');
            $regular = $tracks['Regular'];
            $priority = $tracks['Priority'];
            $stations = Station::where('program_id', $aics->id)->orderBy('id')->get();
            $staff = User::where('site_id', $site->id)->where('role', 'staff')->orderBy('id')->get();
            $tokens = Token::where('site_id', $site->id)->orderBy('id')->get();
            $clients = DB::table('clients')->where('site_id', $site->id)->get();
            $admin = User::where('site_id', $site->id)->where('role', 'admin')->firstOrFail();
            $clientsArray = $clients->all();

            foreach (self::DAILY_VOLUMES as $dayOffset => $volumes) {
                $volume = $volumes[$volumeKey];
                if ($volume === 0) {
                    continue;
                }
                $baseDate = Carbon::today()->addDays($dayOffset);
                $bindTimes = HistoryHelper::spreadSessionsAcrossDay($volume, $baseDate);
                $sessionRows = [];
                $sessionPlans = [];

                foreach ($bindTimes as $idx => $bindAt) {
                    $tokenIndex = $idx % $tokens->count();
                    $token = $tokens[$tokenIndex];
                    $client = $clientsArray[array_rand($clientsArray)];
                    $isPriority = (rand(1, 100) <= 20);
                    $track = $isPriority ? $priority : $regular;
                    $category = HistoryHelper::pickCategory($track->name);
                    $outcome = HistoryHelper::pickOutcome($dayOffset);

                    $callAt = $bindAt->copy()->addMinutes(rand(2, 8));
                    $lastStationId = $stations[0]->id;
                    $lastStep = 1;
                    $completedAt = null;
                    $step1In = $step1Out = $step2In = $step2Out = $step3In = $step3Out = $step4In = $step4Out = null;

                    if ($outcome === 'completed') {
                        $step1In = $callAt->copy()->addMinutes(1);
                        $step1Out = $step1In->copy()->addMinutes(rand(4, 12));
                        $step2In = $step1Out->copy()->addMinutes(1);
                        $step2Out = $step2In->copy()->addMinutes(rand(4, 12));
                        $step3In = $step2Out->copy()->addMinutes(1);
                        $step3Out = $step3In->copy()->addMinutes(rand(4, 12));
                        $step4In = $step3Out->copy()->addMinutes(1);
                        $step4Out = $step4In->copy()->addMinutes(rand(4, 12));
                        $completedAt = $step4Out;
                        $lastStationId = $stations[3]->id;
                        $lastStep = 4;
                    } elseif ($outcome === 'no_show') {
                        $completedAt = $callAt->copy()->addMinutes(12);
                        $lastStationId = $stations[0]->id;
                        $lastStep = 1;
                    } else {
                        $step1In = $callAt->copy()->addMinutes(1);
                        $completedAt = $step1In->copy()->addMinutes(rand(5, 15));
                        $lastStationId = $stations[0]->id;
                        $lastStep = 1;
                    }

                    $sessionRows[] = [
                        'token_id' => $token->id,
                        'client_id' => $client->id,
                        'program_id' => $aics->id,
                        'track_id' => $track->id,
                        'alias' => $token->physical_id,
                        'client_category' => $category,
                        'current_station_id' => $lastStationId,
                        'current_step_order' => $lastStep,
                        'status' => $outcome,
                        'started_at' => $bindAt->toDateTimeString(),
                        'completed_at' => $completedAt?->toDateTimeString(),
                        'no_show_attempts' => $outcome === 'no_show' ? rand(1, 2) : 0,
                        'is_on_hold' => 0,
                        'held_at' => null,
                        'held_order' => null,
                        'holding_station_id' => null,
                        'station_queue_position' => rand(1, 8),
                        'queued_at_station' => $bindAt->toDateTimeString(),
                        'override_steps' => null,
                        'identity_registration_id' => null,
                        'created_at' => $bindAt->toDateTimeString(),
                        'updated_at' => ($completedAt ?? $callAt)->toDateTimeString(),
                    ];

                    $sessionPlans[] = [
                        'token_id' => $token->id,
                        'started_at' => $bindAt->toDateTimeString(),
                        'outcome' => $outcome,
                        'bind_at' => $bindAt,
                        'call_at' => $callAt,
                        'stations' => $stations,
                        'staff' => $staff,
                        'admin_id' => $admin->id,
                        'completed_at' => $completedAt,
                        'step1_in' => $step1In ?? null,
                        'step1_out' => $step1Out ?? null,
                        'step2_in' => $step2In ?? null,
                        'step2_out' => $step2Out ?? null,
                        'step3_in' => $step3In ?? null,
                        'step3_out' => $step3Out ?? null,
                        'step4_in' => $step4In ?? null,
                        'step4_out' => $step4Out ?? null,
                    ];
                }

                if (empty($sessionRows)) {
                    continue;
                }

                DB::table('queue_sessions')->insert($sessionRows);

                $insertedSessions = DB::table('queue_sessions')
                    ->where('program_id', $aics->id)
                    ->whereDate('started_at', $baseDate->toDateString())
                    ->orderBy('id')
                    ->get(['id', 'token_id', 'started_at', 'status', 'current_station_id']);

                $logRows = [];
                foreach ($insertedSessions as $session) {
                    $plan = null;
                    foreach ($sessionPlans as $p) {
                        if ((int) $p['token_id'] === (int) $session->token_id && $p['started_at'] === $session->started_at) {
                            $plan = $p;
                            break;
                        }
                    }
                    if (! $plan) {
                        continue;
                    }
                    $stations = $plan['stations'];
                    $staff = $plan['staff'];
                    $adminId = $plan['admin_id'];

                    $logRows[] = [
                        'session_id' => $session->id,
                        'station_id' => null,
                        'staff_user_id' => $adminId,
                        'action_type' => 'bind',
                        'previous_station_id' => null,
                        'next_station_id' => null,
                        'remarks' => null,
                        'metadata' => null,
                        'created_at' => $plan['bind_at']->toDateTimeString(),
                    ];
                    $logRows[] = [
                        'session_id' => $session->id,
                        'station_id' => $stations[0]->id,
                        'staff_user_id' => $staff[0]->id,
                        'action_type' => 'call',
                        'previous_station_id' => null,
                        'next_station_id' => null,
                        'remarks' => null,
                        'metadata' => null,
                        'created_at' => $plan['call_at']->toDateTimeString(),
                    ];

                    if ($plan['outcome'] === 'completed') {
                        $logRows[] = ['session_id' => $session->id, 'station_id' => $stations[0]->id, 'staff_user_id' => $staff[0]->id, 'action_type' => 'check_in', 'previous_station_id' => null, 'next_station_id' => null, 'remarks' => null, 'metadata' => null, 'created_at' => $plan['step1_in']->toDateTimeString()];
                        $logRows[] = ['session_id' => $session->id, 'station_id' => $stations[0]->id, 'staff_user_id' => $staff[0]->id, 'action_type' => 'transfer', 'previous_station_id' => null, 'next_station_id' => $stations[1]->id, 'remarks' => null, 'metadata' => null, 'created_at' => $plan['step1_out']->toDateTimeString()];
                        $logRows[] = ['session_id' => $session->id, 'station_id' => $stations[1]->id, 'staff_user_id' => $staff[1]->id, 'action_type' => 'check_in', 'previous_station_id' => null, 'next_station_id' => null, 'remarks' => null, 'metadata' => null, 'created_at' => $plan['step2_in']->toDateTimeString()];
                        $logRows[] = ['session_id' => $session->id, 'station_id' => $stations[1]->id, 'staff_user_id' => $staff[1]->id, 'action_type' => 'transfer', 'previous_station_id' => null, 'next_station_id' => $stations[2]->id, 'remarks' => null, 'metadata' => null, 'created_at' => $plan['step2_out']->toDateTimeString()];
                        $logRows[] = ['session_id' => $session->id, 'station_id' => $stations[2]->id, 'staff_user_id' => $staff[2]->id, 'action_type' => 'check_in', 'previous_station_id' => null, 'next_station_id' => null, 'remarks' => null, 'metadata' => null, 'created_at' => $plan['step3_in']->toDateTimeString()];
                        $logRows[] = ['session_id' => $session->id, 'station_id' => $stations[2]->id, 'staff_user_id' => $staff[2]->id, 'action_type' => 'transfer', 'previous_station_id' => null, 'next_station_id' => $stations[3]->id, 'remarks' => null, 'metadata' => null, 'created_at' => $plan['step3_out']->toDateTimeString()];
                        $logRows[] = ['session_id' => $session->id, 'station_id' => $stations[3]->id, 'staff_user_id' => $staff[3]->id, 'action_type' => 'check_in', 'previous_station_id' => null, 'next_station_id' => null, 'remarks' => null, 'metadata' => null, 'created_at' => $plan['step4_in']->toDateTimeString()];
                        $logRows[] = ['session_id' => $session->id, 'station_id' => $stations[3]->id, 'staff_user_id' => $staff[3]->id, 'action_type' => 'complete', 'previous_station_id' => null, 'next_station_id' => null, 'remarks' => null, 'metadata' => null, 'created_at' => $plan['completed_at']->toDateTimeString()];
                    } elseif ($plan['outcome'] === 'no_show') {
                        $logRows[] = [
                            'session_id' => $session->id,
                            'station_id' => $stations[0]->id,
                            'staff_user_id' => $staff[0]->id,
                            'action_type' => 'no_show',
                            'previous_station_id' => null,
                            'next_station_id' => null,
                            'remarks' => null,
                            'metadata' => null,
                            'created_at' => $plan['call_at']->copy()->addMinutes(12)->toDateTimeString(),
                        ];
                    } else {
                        $logRows[] = ['session_id' => $session->id, 'station_id' => $stations[0]->id, 'staff_user_id' => $staff[0]->id, 'action_type' => 'check_in', 'previous_station_id' => null, 'next_station_id' => null, 'remarks' => null, 'metadata' => null, 'created_at' => $plan['step1_in']->toDateTimeString()];
                        $logRows[] = [
                            'session_id' => $session->id,
                            'station_id' => $stations[0]->id,
                            'staff_user_id' => $staff[0]->id,
                            'action_type' => 'cancel',
                            'previous_station_id' => null,
                            'next_station_id' => null,
                            'remarks' => null,
                            'metadata' => null,
                            'created_at' => $plan['completed_at']->toDateTimeString(),
                        ];
                    }
                }

                if (! empty($logRows)) {
                    foreach (array_chunk($logRows, 200) as $chunk) {
                        DB::table('transaction_logs')->insert($chunk);
                    }
                }
            }

            DB::table('tokens')->where('site_id', $site->id)->update([
                'status' => 'available',
                'current_session_id' => null,
                'updated_at' => now(),
            ]);
        }
    }
}
