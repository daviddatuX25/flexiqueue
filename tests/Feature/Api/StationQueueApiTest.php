<?php

namespace Tests\Feature\Api;

use App\Models\Client;
use App\Models\IdentityRegistration;
use App\Models\Program;
use App\Models\ProgramStationAssignment;
use App\Models\ServiceTrack;
use App\Models\Session;
use App\Models\Site;
use App\Models\Station;
use App\Models\Token;
use App\Models\TrackStep;
use App\Models\TransactionLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Per 08-API-SPEC-PHASE1 §4: Station queue API. Auth: staff assigned or supervisor/admin.
 */
class StationQueueApiTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $otherStaff;

    private User $supervisor;

    private User $admin;

    private Program $program;

    private ServiceTrack $track;

    private Station $station1;

    private Station $station2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::factory()->create(['role' => 'staff']);
        $this->otherStaff = User::factory()->create(['role' => 'staff']);
        $this->supervisor = User::factory()->create(['role' => 'staff']);
        $this->admin = User::factory()->create(['role' => 'admin']);

        $this->program = Program::create([
            'name' => 'Test Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $this->staff->id,
        ]);
        $this->station1 = Station::create([
            'program_id' => $this->program->id,
            'name' => 'Interview',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $this->station2 = Station::create([
            'program_id' => $this->program->id,
            'name' => 'Cashier',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $this->track = ServiceTrack::create([
            'program_id' => $this->program->id,
            'name' => 'Priority',
            'is_default' => true,
            'color_code' => '#F59E0B',
        ]);
        TrackStep::create([
            'track_id' => $this->track->id,
            'station_id' => $this->station1->id,
            'step_order' => 1,
            'is_required' => true,
        ]);
        TrackStep::create([
            'track_id' => $this->track->id,
            'station_id' => $this->station2->id,
            'step_order' => 2,
            'is_required' => true,
        ]);

        // Assign staff to station1 only
        $this->staff->update(['assigned_station_id' => $this->station1->id]);
        $this->otherStaff->update(['assigned_station_id' => $this->station2->id]);

        $this->program->supervisedBy()->attach($this->supervisor->id);
    }

    public function test_staff_assigned_to_station_can_fetch_queue_returns_200(): void
    {
        $response = $this->actingAs($this->staff)->getJson("/api/stations/{$this->station1->id}/queue");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'station' => ['id', 'name', 'client_capacity', 'serving_count'],
            'priority_first',
            'balance_mode',
            'serving',
            'no_show_timer_seconds',
            'waiting',
            'next_to_call',
            'stats' => ['total_waiting', 'total_served_today', 'avg_service_time_minutes'],
        ]);
        $response->assertJsonPath('station.id', $this->station1->id);
        $response->assertJsonPath('station.name', 'Interview');
        $response->assertJsonPath('serving', []);
        $response->assertJsonPath('stats.total_waiting', 0);
        $response->assertJsonPath('stats.total_served_today', 0);
    }

    public function test_queue_includes_unverified_flag_when_session_has_pending_identity_registration(): void
    {
        $token = $this->createToken('U1');
        $reg = IdentityRegistration::create([
            'program_id' => $this->program->id,
            'session_id' => null,
            'name' => 'Unverified Person',
            'birth_year' => 1990,
            'client_category' => 'Regular',
            'status' => 'pending',
            'requested_at' => now(),
        ]);
        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $this->program->id,
            'track_id' => $this->track->id,
            'alias' => 'U1',
            'client_id' => null,
            'identity_registration_id' => $reg->id,
            'client_category' => 'Regular',
            'current_station_id' => $this->station1->id,
            'current_step_order' => 1,
            'station_queue_position' => 1,
            'status' => 'waiting',
            'queued_at_station' => now(),
        ]);
        $reg->update(['session_id' => $session->id]);
        $token->update(['status' => 'in_use', 'current_session_id' => $session->id]);

        $response = $this->actingAs($this->staff)->getJson("/api/stations/{$this->station1->id}/queue");

        $response->assertStatus(200);
        $waiting = $response->json('waiting');
        $this->assertIsArray($waiting);
        $found = collect($waiting)->firstWhere('session_id', $session->id);
        $this->assertNotNull($found);
        $this->assertTrue($found['unverified'] ?? false);
    }

    public function test_staff_assigned_sees_serving_when_one_session_serving(): void
    {
        $token = $this->createToken('A1');
        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $this->program->id,
            'track_id' => $this->track->id,
            'alias' => 'A1',
            'client_category' => 'PWD',
            'current_station_id' => $this->station1->id,
            'current_step_order' => 1,
            'status' => 'serving',
            'no_show_attempts' => 0,
        ]);
        $token->update(['status' => 'in_use', 'current_session_id' => $session->id]);

        $response = $this->actingAs($this->staff)->getJson("/api/stations/{$this->station1->id}/queue");

        $response->assertStatus(200);
        $response->assertJsonPath('serving.0.session_id', $session->id);
        $response->assertJsonPath('serving.0.alias', 'A1');
        $response->assertJsonPath('serving.0.track', 'Priority');
        $response->assertJsonPath('serving.0.client_category', 'PWD');
        $response->assertJsonPath('serving.0.status', 'serving');
        $response->assertJsonPath('serving.0.current_step_order', 1);
        $response->assertJsonPath('serving.0.total_steps', 2);
        $response->assertJsonPath('serving.0.no_show_attempts', 0);
        $response->assertJsonPath('station.serving_count', 1);
        // Per flexiqueue-ui3: queue payload includes process for each serving/waiting session
        $response->assertJsonStructure(['serving' => [['session_id', 'alias', 'track', 'process_id', 'process_name']]]);
        // Staff-only: client_name present; null when session has no client/identity
        $response->assertJsonPath('serving.0.client_name', null);
    }

    /** Station page staff-only: queue includes client_name when session has linked client. */
    public function test_queue_includes_client_name_when_session_has_client(): void
    {
        $site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'api_key_hash' => \Illuminate\Support\Facades\Hash::make('key'),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $this->program->update(['site_id' => $site->id]);
        $client = Client::factory()->create([
            'site_id' => $site->id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ]);
        $token = $this->createToken('A1');
        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $this->program->id,
            'track_id' => $this->track->id,
            'alias' => 'A1',
            'client_id' => $client->id,
            'client_category' => 'Regular',
            'current_station_id' => $this->station1->id,
            'current_step_order' => 1,
            'status' => 'serving',
            'no_show_attempts' => 0,
        ]);
        $token->update(['status' => 'in_use', 'current_session_id' => $session->id]);

        $response = $this->actingAs($this->staff)->getJson("/api/stations/{$this->station1->id}/queue");

        $response->assertStatus(200);
        $response->assertJsonPath('serving.0.client_name', $client->display_name);
    }

    /** Station page staff-only: queue includes client_name from identity registration when no client. */
    public function test_queue_includes_client_name_from_identity_registration_when_no_client(): void
    {
        $reg = IdentityRegistration::create([
            'program_id' => $this->program->id,
            'session_id' => null,
            'first_name' => 'Unverified',
            'last_name' => 'Person',
            'client_category' => 'Regular',
            'status' => 'pending',
            'requested_at' => now(),
        ]);
        $token = $this->createToken('U1');
        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $this->program->id,
            'track_id' => $this->track->id,
            'alias' => 'U1',
            'client_id' => null,
            'identity_registration_id' => $reg->id,
            'client_category' => 'Regular',
            'current_station_id' => $this->station1->id,
            'current_step_order' => 1,
            'station_queue_position' => 1,
            'status' => 'waiting',
            'queued_at_station' => now(),
        ]);
        $reg->update(['session_id' => $session->id]);
        $token->update(['status' => 'in_use', 'current_session_id' => $session->id]);

        $response = $this->actingAs($this->staff)->getJson("/api/stations/{$this->station1->id}/queue");

        $response->assertStatus(200);
        $waiting = $response->json('waiting');
        $found = collect($waiting)->firstWhere('session_id', $session->id);
        $this->assertNotNull($found);
        $this->assertSame('Unverified Person', $found['client_name'] ?? null);
    }

    /** Per flexiqueue-ui3: when track step has process, serving payload includes process_id and process_name */
    public function test_queue_serving_includes_process_when_step_has_process(): void
    {
        $process = \App\Models\Process::create([
            'program_id' => $this->program->id,
            'name' => 'Verification',
            'description' => null,
        ]);
        \Illuminate\Support\Facades\DB::table('station_process')->insert([
            'station_id' => $this->station1->id,
            'process_id' => $process->id,
        ]);
        $step = TrackStep::where('track_id', $this->track->id)->where('step_order', 1)->first();
        $step->update(['process_id' => $process->id]);

        $token = $this->createToken('A1');
        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $this->program->id,
            'track_id' => $this->track->id,
            'alias' => 'A1',
            'client_category' => 'Regular',
            'current_station_id' => $this->station1->id,
            'current_step_order' => 1,
            'status' => 'serving',
            'no_show_attempts' => 0,
        ]);
        $token->update(['status' => 'in_use', 'current_session_id' => $session->id]);

        $response = $this->actingAs($this->staff)->getJson("/api/stations/{$this->station1->id}/queue");

        $response->assertStatus(200);
        $response->assertJsonPath('serving.0.process_id', $process->id);
        $response->assertJsonPath('serving.0.process_name', 'Verification');
    }

    /** Per flexiqueue-ui3: waiting list items include process_id and process_name */
    public function test_queue_waiting_includes_process_keys(): void
    {
        $token = $this->createToken('A1');
        Session::create([
            'token_id' => $token->id,
            'program_id' => $this->program->id,
            'track_id' => $this->track->id,
            'alias' => 'A1',
            'client_category' => 'Regular',
            'current_station_id' => $this->station1->id,
            'current_step_order' => 1,
            'status' => 'waiting',
        ]);

        $response = $this->actingAs($this->staff)->getJson("/api/stations/{$this->station1->id}/queue");

        $response->assertStatus(200);
        $response->assertJsonStructure(['waiting' => [['session_id', 'alias', 'process_id', 'process_name']]]);
    }

    /** Per ISSUES-ELABORATION §4: custom-override sessions use override step count so station UI shows Complete on last step */
    public function test_serving_with_override_steps_uses_override_count_for_total_steps(): void
    {
        $token = $this->createToken('C1');
        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $this->program->id,
            'track_id' => $this->track->id,
            'alias' => 'C1',
            'client_category' => 'Regular',
            'current_station_id' => $this->station1->id,
            'current_step_order' => 1,
            'status' => 'serving',
            'no_show_attempts' => 0,
            'override_steps' => [$this->station1->id],
        ]);
        $token->update(['status' => 'in_use', 'current_session_id' => $session->id]);

        $response = $this->actingAs($this->staff)->getJson("/api/stations/{$this->station1->id}/queue");

        $response->assertStatus(200);
        $response->assertJsonPath('serving.0.session_id', $session->id);
        $response->assertJsonPath('serving.0.current_step_order', 1);
        $response->assertJsonPath('serving.0.total_steps', 1);
    }

    public function test_staff_assigned_sees_waiting_ordered_by_started_at_fifo(): void
    {
        $t1 = $this->createToken('A1');
        $t2 = $this->createToken('A2');
        $t3 = $this->createToken('A3');
        $s1 = Session::create([
            'token_id' => $t1->id,
            'program_id' => $this->program->id,
            'track_id' => $this->track->id,
            'alias' => 'A1',
            'client_category' => 'Regular',
            'current_station_id' => $this->station1->id,
            'current_step_order' => 1,
            'status' => 'waiting',
            'started_at' => now()->subMinutes(5),
        ]);
        $s2 = Session::create([
            'token_id' => $t2->id,
            'program_id' => $this->program->id,
            'track_id' => $this->track->id,
            'alias' => 'A2',
            'client_category' => 'Senior',
            'current_station_id' => $this->station1->id,
            'current_step_order' => 1,
            'status' => 'waiting',
            'started_at' => now()->subMinutes(3),
        ]);
        $s3 = Session::create([
            'token_id' => $t3->id,
            'program_id' => $this->program->id,
            'track_id' => $this->track->id,
            'alias' => 'A3',
            'client_category' => 'PWD',
            'current_station_id' => $this->station1->id,
            'current_step_order' => 1,
            'status' => 'waiting',
            'started_at' => now()->subMinutes(10),
        ]);
        $t1->update(['status' => 'in_use', 'current_session_id' => $s1->id]);
        $t2->update(['status' => 'in_use', 'current_session_id' => $s2->id]);
        $t3->update(['status' => 'in_use', 'current_session_id' => $s3->id]);

        $response = $this->actingAs($this->staff)->getJson("/api/stations/{$this->station1->id}/queue");

        $response->assertStatus(200);
        $response->assertJsonPath('serving', []);
        $response->assertJsonPath('stats.total_waiting', 3);
        $waiting = $response->json('waiting');
        $this->assertCount(3, $waiting);
        // Priority first ON (default): PWD/Senior first (A3, A2), then Regular (A1). Within priority: FIFO by started_at.
        $this->assertSame('A3', $waiting[0]['alias']);
        $this->assertSame('A2', $waiting[1]['alias']);
        $this->assertSame('A1', $waiting[2]['alias']);
        $this->assertArrayHasKey('queued_at', $waiting[0]);
    }

    public function test_priority_first_orders_triage_combined_category_as_priority(): void
    {
        // Triage sends "PWD / Senior / Pregnant" — must be treated as priority
        $t1 = $this->createToken('B1');
        $t2 = $this->createToken('B2');
        $s1 = Session::create([
            'token_id' => $t1->id,
            'program_id' => $this->program->id,
            'track_id' => $this->track->id,
            'alias' => 'B1',
            'client_category' => 'Regular',
            'current_station_id' => $this->station1->id,
            'current_step_order' => 1,
            'status' => 'waiting',
            'started_at' => now()->subMinutes(5),
        ]);
        $s2 = Session::create([
            'token_id' => $t2->id,
            'program_id' => $this->program->id,
            'track_id' => $this->track->id,
            'alias' => 'B2',
            'client_category' => 'PWD / Senior / Pregnant',
            'current_station_id' => $this->station1->id,
            'current_step_order' => 1,
            'status' => 'waiting',
            'started_at' => now()->subMinutes(3),
        ]);
        $t1->update(['status' => 'in_use', 'current_session_id' => $s1->id]);
        $t2->update(['status' => 'in_use', 'current_session_id' => $s2->id]);

        $response = $this->actingAs($this->staff)->getJson("/api/stations/{$this->station1->id}/queue");

        $response->assertStatus(200);
        $waiting = $response->json('waiting');
        $this->assertCount(2, $waiting);
        // B2 (PWD / Senior / Pregnant) should be first when priority_first is ON
        $this->assertSame('B2', $waiting[0]['alias']);
        $this->assertSame('B1', $waiting[1]['alias']);
        $response->assertJsonPath('next_to_call.alias', 'B2');
    }

    public function test_queue_includes_call_next_requires_override_when_fifo_with_regular_before_priority(): void
    {
        $this->station1->update(['priority_first_override' => false]);
        $t1 = $this->createToken('B1');
        $t2 = $this->createToken('B2');
        $s1 = Session::create([
            'token_id' => $t1->id,
            'program_id' => $this->program->id,
            'track_id' => $this->track->id,
            'alias' => 'B1',
            'client_category' => 'Regular',
            'current_station_id' => $this->station1->id,
            'current_step_order' => 1,
            'status' => 'waiting',
            'queued_at_station' => now()->subMinutes(5),
        ]);
        $s2 = Session::create([
            'token_id' => $t2->id,
            'program_id' => $this->program->id,
            'track_id' => $this->track->id,
            'alias' => 'B2',
            'client_category' => 'PWD',
            'current_station_id' => $this->station1->id,
            'current_step_order' => 1,
            'status' => 'waiting',
            'queued_at_station' => now()->subMinutes(3),
        ]);
        $t1->update(['status' => 'in_use', 'current_session_id' => $s1->id]);
        $t2->update(['status' => 'in_use', 'current_session_id' => $s2->id]);

        $response = $this->actingAs($this->staff)->getJson("/api/stations/{$this->station1->id}/queue");

        $response->assertStatus(200);
        $response->assertJsonPath('call_next_requires_override', true);
        $response->assertJsonPath('require_permission_before_override', true);
        $response->assertJsonPath('next_to_call.alias', 'B1');
    }

    public function test_staff_assigned_sees_stats_when_check_in_logs_exist(): void
    {
        $token = $this->createToken('A1');
        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $this->program->id,
            'track_id' => $this->track->id,
            'alias' => 'A1',
            'client_category' => 'PWD',
            'current_station_id' => $this->station1->id,
            'current_step_order' => 1,
            'status' => 'completed',
        ]);
        $token->update(['status' => 'available', 'current_session_id' => null]);

        TransactionLog::create([
            'session_id' => $session->id,
            'station_id' => $this->station1->id,
            'staff_user_id' => $this->staff->id,
            'action_type' => 'check_in',
            'previous_station_id' => null,
            'next_station_id' => null,
        ]);
        TransactionLog::create([
            'session_id' => $session->id,
            'station_id' => $this->station1->id,
            'staff_user_id' => $this->staff->id,
            'action_type' => 'transfer',
            'previous_station_id' => $this->station1->id,
            'next_station_id' => $this->station2->id,
        ]);

        $response = $this->actingAs($this->staff)->getJson("/api/stations/{$this->station1->id}/queue");

        $response->assertStatus(200);
        $response->assertJsonPath('stats.total_served_today', 1);
        // avg_service_time_minutes may be 0 if times are same or logic differs; we assert structure
        $response->assertJsonStructure(['stats' => ['avg_service_time_minutes']]);
    }

    public function test_served_count_is_idempotent_same_session_two_check_ins_count_as_one(): void
    {
        $token = $this->createToken('B1');
        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $this->program->id,
            'track_id' => $this->track->id,
            'alias' => 'B1',
            'client_category' => 'regular',
            'current_station_id' => $this->station1->id,
            'current_step_order' => 1,
            'status' => 'completed',
        ]);
        $token->update(['status' => 'available', 'current_session_id' => null]);

        $base = now()->startOfDay()->addHours(9);
        TransactionLog::create([
            'session_id' => $session->id,
            'station_id' => $this->station1->id,
            'staff_user_id' => $this->staff->id,
            'action_type' => 'check_in',
            'previous_station_id' => null,
            'next_station_id' => null,
            'created_at' => $base,
        ]);
        TransactionLog::create([
            'session_id' => $session->id,
            'station_id' => $this->station1->id,
            'staff_user_id' => $this->staff->id,
            'action_type' => 'check_in',
            'previous_station_id' => null,
            'next_station_id' => null,
            'created_at' => $base->copy()->addMinutes(2),
        ]);
        TransactionLog::create([
            'session_id' => $session->id,
            'station_id' => $this->station1->id,
            'staff_user_id' => $this->staff->id,
            'action_type' => 'transfer',
            'previous_station_id' => $this->station1->id,
            'next_station_id' => $this->station2->id,
            'created_at' => $base->copy()->addMinutes(5),
        ]);

        $response = $this->actingAs($this->staff)->getJson("/api/stations/{$this->station1->id}/queue");

        $response->assertStatus(200);
        $response->assertJsonPath('stats.total_served_today', 1);
        // Avg = first check_in to transfer = 5 min (JSON may return int)
        $this->assertSame(5, (int) $response->json('stats.avg_service_time_minutes'));
    }

    public function test_avg_service_time_averages_durations_per_session(): void
    {
        $base = now()->startOfDay()->addHours(10);
        $token1 = $this->createToken('C1');
        $session1 = Session::create([
            'token_id' => $token1->id,
            'program_id' => $this->program->id,
            'track_id' => $this->track->id,
            'alias' => 'C1',
            'client_category' => 'regular',
            'current_station_id' => $this->station1->id,
            'current_step_order' => 1,
            'status' => 'completed',
        ]);
        $token1->update(['status' => 'available', 'current_session_id' => null]);
        TransactionLog::create([
            'session_id' => $session1->id,
            'station_id' => $this->station1->id,
            'staff_user_id' => $this->staff->id,
            'action_type' => 'check_in',
            'created_at' => $base,
        ]);
        TransactionLog::create([
            'session_id' => $session1->id,
            'station_id' => $this->station1->id,
            'staff_user_id' => $this->staff->id,
            'action_type' => 'complete',
            'station_id' => $this->station1->id,
            'created_at' => $base->copy()->addMinutes(4),
        ]);

        $token2 = $this->createToken('D1');
        $session2 = Session::create([
            'token_id' => $token2->id,
            'program_id' => $this->program->id,
            'track_id' => $this->track->id,
            'alias' => 'D1',
            'client_category' => 'regular',
            'current_station_id' => $this->station1->id,
            'current_step_order' => 1,
            'status' => 'completed',
        ]);
        $token2->update(['status' => 'available', 'current_session_id' => null]);
        TransactionLog::create([
            'session_id' => $session2->id,
            'station_id' => $this->station1->id,
            'staff_user_id' => $this->staff->id,
            'action_type' => 'check_in',
            'created_at' => $base->copy()->addHour(),
        ]);
        TransactionLog::create([
            'session_id' => $session2->id,
            'station_id' => $this->station1->id,
            'staff_user_id' => $this->staff->id,
            'action_type' => 'transfer',
            'previous_station_id' => $this->station1->id,
            'next_station_id' => $this->station2->id,
            'created_at' => $base->copy()->addHour()->addMinutes(10),
        ]);

        $response = $this->actingAs($this->staff)->getJson("/api/stations/{$this->station1->id}/queue");

        $response->assertStatus(200);
        $response->assertJsonPath('stats.total_served_today', 2);
        // 4 min and 10 min → avg 7 (JSON may return int)
        $this->assertSame(7, (int) $response->json('stats.avg_service_time_minutes'));
    }

    public function test_staff_not_assigned_to_station_gets_403(): void
    {
        $response = $this->actingAs($this->otherStaff)->getJson("/api/stations/{$this->station1->id}/queue");

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'You are not assigned to this station.');
    }

    public function test_staff_with_program_station_assignment_but_null_assigned_station_id_can_fetch_queue(): void
    {
        $staffWithPsaOnly = User::factory()->create(['role' => 'staff', 'assigned_station_id' => null]);
        ProgramStationAssignment::create([
            'program_id' => $this->program->id,
            'user_id' => $staffWithPsaOnly->id,
            'station_id' => $this->station1->id,
        ]);

        $response = $this->actingAs($staffWithPsaOnly)->getJson("/api/stations/{$this->station1->id}/queue");

        $response->assertStatus(200);
        $response->assertJsonPath('station.id', $this->station1->id);
    }

    public function test_supervisor_can_fetch_any_station_queue(): void
    {
        $response = $this->actingAs($this->supervisor)->getJson("/api/stations/{$this->station1->id}/queue");

        $response->assertStatus(200);
        $response->assertJsonPath('station.id', $this->station1->id);
    }

    public function test_admin_can_fetch_any_station_queue(): void
    {
        $response = $this->actingAs($this->admin)->getJson("/api/stations/{$this->station1->id}/queue");

        $response->assertStatus(200);
        $response->assertJsonPath('station.id', $this->station1->id);
    }

    public function test_guest_cannot_fetch_queue_returns_401(): void
    {
        $response = $this->getJson("/api/stations/{$this->station1->id}/queue");

        $response->assertStatus(401);
    }

    public function test_station_not_found_returns_404(): void
    {
        $response = $this->actingAs($this->staff)->getJson('/api/stations/99999/queue');

        $response->assertStatus(404);
    }

    public function test_supervisor_can_set_priority_first_override(): void
    {
        $response = $this->actingAs($this->supervisor)->postJson(
            "/api/stations/{$this->station1->id}/priority-first",
            ['priority_first' => false]
        );

        $response->assertStatus(200);
        $response->assertJsonPath('station.priority_first_override', false);
        $this->assertDatabaseHas('stations', ['id' => $this->station1->id, 'priority_first_override' => false]);

        $queueResponse = $this->actingAs($this->staff)->getJson("/api/stations/{$this->station1->id}/queue");
        $queueResponse->assertStatus(200);
        $queueResponse->assertJsonPath('priority_first', false);
    }

    public function test_staff_cannot_set_priority_first_returns_403(): void
    {
        $response = $this->actingAs($this->staff)->postJson(
            "/api/stations/{$this->station1->id}/priority-first",
            ['priority_first' => false]
        );

        $response->assertStatus(403);
    }

    public function test_empty_queue_returns_empty_serving_and_empty_waiting(): void
    {
        $response = $this->actingAs($this->staff)->getJson("/api/stations/{$this->station1->id}/queue");

        $response->assertStatus(200);
        $response->assertJsonPath('serving', []);
        $response->assertJsonPath('waiting', []);
        $response->assertJsonPath('stats.total_waiting', 0);
        $response->assertJsonPath('stats.total_served_today', 0);
        $response->assertJsonPath('stats.avg_service_time_minutes', 0);
    }

    public function test_get_stations_returns_list_for_active_program(): void
    {
        $response = $this->actingAs($this->staff)->getJson('/api/stations');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'stations' => [
                '*' => ['id', 'name', 'is_active', 'queue_count', 'assigned_staff'],
            ],
        ]);
        $stations = $response->json('stations');
        $this->assertCount(2, $stations);
        $names = array_column($stations, 'name');
        $this->assertContains('Interview', $names);
        $this->assertContains('Cashier', $names);
    }

    public function test_get_stations_includes_queue_count_and_assigned_staff(): void
    {
        $response = $this->actingAs($this->staff)->getJson('/api/stations');

        $response->assertStatus(200);
        $interview = collect($response->json('stations'))->firstWhere('name', 'Interview');
        $this->assertNotNull($interview);
        $this->assertArrayHasKey('queue_count', $interview);
        $this->assertArrayHasKey('assigned_staff', $interview);
        $this->assertIsArray($interview['assigned_staff']);
        $this->assertCount(1, $interview['assigned_staff']);
        $this->assertSame($this->staff->id, $interview['assigned_staff'][0]['id']);
        $this->assertSame($this->staff->name, $interview['assigned_staff'][0]['name']);
    }

    public function test_get_stations_inactive_program_returns_empty_stations(): void
    {
        $this->program->update(['is_active' => false]);

        $response = $this->actingAs($this->staff)->getJson('/api/stations');

        $response->assertStatus(200);
        $response->assertJsonPath('stations', []);
    }

    public function test_guest_cannot_get_stations_returns_401(): void
    {
        $response = $this->getJson('/api/stations');

        $response->assertStatus(401);
    }

    public function test_staff_unassigned_gets_422_when_requesting_stations_list(): void
    {
        $unassigned = User::factory()->create(['role' => 'staff', 'assigned_station_id' => null]);

        $response = $this->actingAs($unassigned)->getJson('/api/stations');

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'No station assigned.');
    }

    public function test_admin_without_station_gets_422_when_requesting_stations_list_without_program_context(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'assigned_station_id' => null]);

        $response = $this->actingAs($admin)->getJson('/api/stations');

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Program not selected or inactive.');
    }

    public function test_admin_without_station_can_get_stations_list_when_session_has_program(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'assigned_station_id' => null]);

        $response = $this
            ->withSession([\App\Http\Controllers\StationPageController::SESSION_KEY_PROGRAM_ID => $this->program->id])
            ->actingAs($admin)
            ->getJson('/api/stations');

        $response->assertStatus(200);
        $stations = $response->json('stations');
        $this->assertIsArray($stations);
        $this->assertCount(2, $stations);
    }

    public function test_queue_excludes_sessions_at_other_stations(): void
    {
        $token = $this->createToken('A1');
        Session::create([
            'token_id' => $token->id,
            'program_id' => $this->program->id,
            'track_id' => $this->track->id,
            'alias' => 'A1',
            'client_category' => 'PWD',
            'current_station_id' => $this->station2->id, // at Cashier, not Interview
            'current_step_order' => 2,
            'status' => 'waiting',
        ]);
        $token->update(['status' => 'in_use', 'current_session_id' => $token->queueSessions()->first()->id]);

        $response = $this->actingAs($this->staff)->getJson("/api/stations/{$this->station1->id}/queue");

        $response->assertStatus(200);
        $response->assertJsonPath('stats.total_waiting', 0);
        $response->assertJsonPath('waiting', []);
        $response->assertJsonPath('serving', []);
    }

    public function test_queue_excludes_completed_and_cancelled_sessions(): void
    {
        $token = $this->createToken('A1');
        Session::create([
            'token_id' => $token->id,
            'program_id' => $this->program->id,
            'track_id' => $this->track->id,
            'alias' => 'A1',
            'client_category' => 'PWD',
            'current_station_id' => $this->station1->id,
            'current_step_order' => 1,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($this->staff)->getJson("/api/stations/{$this->station1->id}/queue");

        $response->assertStatus(200);
        $response->assertJsonPath('stats.total_waiting', 0);
        $response->assertJsonPath('waiting', []);
    }

    /** A.6.2: With two programs active, each station queue remains isolated per program. */
    public function test_multi_program_station_queue_is_isolated_per_program(): void
    {
        // Program B: second program with its own station and waiting session
        $programB = Program::create([
            'name' => 'Program B',
            'description' => null,
            'is_active' => true,
            'created_by' => $this->staff->id,
        ]);
        $stationB = Station::create([
            'program_id' => $programB->id,
            'name' => 'B Station',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $trackB = ServiceTrack::create([
            'program_id' => $programB->id,
            'name' => 'Track B',
            'is_default' => true,
            'color_code' => '#AAAAAA',
        ]);
        TrackStep::create([
            'track_id' => $trackB->id,
            'station_id' => $stationB->id,
            'step_order' => 1,
            'is_required' => true,
        ]);
        $tokenB = $this->createToken('QB1');
        Session::create([
            'token_id' => $tokenB->id,
            'program_id' => $programB->id,
            'track_id' => $trackB->id,
            'alias' => 'QB1',
            'client_category' => 'Regular',
            'current_station_id' => $stationB->id,
            'current_step_order' => 1,
            'status' => 'waiting',
            'queued_at_station' => now(),
        ]);

        // Program A: ensure there is a waiting session at station1
        $tokenA = $this->createToken('QA1');
        Session::create([
            'token_id' => $tokenA->id,
            'program_id' => $this->program->id,
            'track_id' => $this->track->id,
            'alias' => 'QA1',
            'client_category' => 'Regular',
            'current_station_id' => $this->station1->id,
            'current_step_order' => 1,
            'status' => 'waiting',
            'queued_at_station' => now(),
        ]);

        // Queue for Program A station must not include Program B alias
        $response = $this->actingAs($this->staff)->getJson("/api/stations/{$this->station1->id}/queue");

        $response->assertStatus(200);
        $waiting = $response->json('waiting');
        $this->assertIsArray($waiting);
        $aliases = array_column($waiting, 'alias');
        $this->assertContains('QA1', $aliases);
        $this->assertNotContains('QB1', $aliases);
    }

    public function test_call_blocked_when_at_client_capacity(): void
    {
        $this->station1->update(['client_capacity' => 1]);
        $token = $this->createToken('A1');
        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $this->program->id,
            'track_id' => $this->track->id,
            'alias' => 'A1',
            'client_category' => 'PWD',
            'current_station_id' => $this->station1->id,
            'current_step_order' => 1,
            'status' => 'called',
            'no_show_attempts' => 0,
        ]);
        $token->update(['status' => 'in_use', 'current_session_id' => $session->id]);

        $t2 = $this->createToken('A2');
        $s2 = Session::create([
            'token_id' => $t2->id,
            'program_id' => $this->program->id,
            'track_id' => $this->track->id,
            'alias' => 'A2',
            'client_category' => 'Regular',
            'current_station_id' => $this->station1->id,
            'current_step_order' => 1,
            'status' => 'waiting',
        ]);
        $t2->update(['status' => 'in_use', 'current_session_id' => $s2->id]);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$s2->id}/call", []);

        $response->assertStatus(409);
        $response->assertJsonFragment(['message' => 'Station at capacity (1). Cannot call more clients.']);
    }

    public function test_session_by_token_returns_session_and_at_this_station(): void
    {
        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).'A1');
        $token->physical_id = 'A1';
        $token->status = 'in_use';
        $token->save();
        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $this->program->id,
            'track_id' => $this->track->id,
            'alias' => 'A1',
            'client_category' => 'Regular',
            'current_station_id' => $this->station1->id,
            'current_step_order' => 1,
            'status' => 'waiting',
        ]);
        $token->update(['current_session_id' => $session->id]);

        $response = $this->actingAs($this->staff)->getJson(
            "/api/stations/{$this->station1->id}/session-by-token?qr_hash=".urlencode($token->qr_code_hash)
        );

        $response->assertStatus(200);
        $response->assertJsonPath('session_id', $session->id);
        $response->assertJsonPath('alias', 'A1');
        $response->assertJsonPath('status', 'waiting');
        $response->assertJsonPath('current_station_id', $this->station1->id);
        $response->assertJsonPath('at_this_station', true);
        $response->assertJsonPath('unverified', false);
        $response->assertJsonPath('client_name', null);
    }

    /** Station page staff-only: session-by-token includes client_name when session has linked client. */
    public function test_session_by_token_includes_client_name_when_session_has_client(): void
    {
        $site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'api_key_hash' => \Illuminate\Support\Facades\Hash::make('key'),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $this->program->update(['site_id' => $site->id]);
        $client = Client::factory()->create([
            'site_id' => $site->id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
        ]);
        $token = $this->createToken('M1');
        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $this->program->id,
            'track_id' => $this->track->id,
            'alias' => 'M1',
            'client_id' => $client->id,
            'client_category' => 'Regular',
            'current_station_id' => $this->station1->id,
            'current_step_order' => 1,
            'status' => 'waiting',
        ]);
        $token->update(['status' => 'in_use', 'current_session_id' => $session->id]);

        $response = $this->actingAs($this->staff)->getJson(
            "/api/stations/{$this->station1->id}/session-by-token?qr_hash=".urlencode($token->qr_code_hash)
        );

        $response->assertStatus(200);
        $response->assertJsonPath('client_name', $client->display_name);
    }

    public function test_session_by_token_when_session_at_other_station_returns_at_this_station_false(): void
    {
        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).'B1');
        $token->physical_id = 'B1';
        $token->status = 'in_use';
        $token->save();
        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $this->program->id,
            'track_id' => $this->track->id,
            'alias' => 'B1',
            'client_category' => 'Regular',
            'current_station_id' => $this->station2->id,
            'current_step_order' => 1,
            'status' => 'waiting',
        ]);
        $token->update(['current_session_id' => $session->id]);

        $response = $this->actingAs($this->staff)->getJson(
            "/api/stations/{$this->station1->id}/session-by-token?qr_hash=".urlencode($token->qr_code_hash)
        );

        $response->assertStatus(200);
        $response->assertJsonPath('at_this_station', false);
        $response->assertJsonPath('current_station_id', $this->station2->id);
    }

    public function test_session_by_token_includes_unverified_flag_when_session_has_pending_identity_registration(): void
    {
        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).'U1');
        $token->physical_id = 'U1';
        $token->status = 'in_use';
        $token->save();

        $registration = IdentityRegistration::create([
            'program_id' => $this->program->id,
            'session_id' => null,
            'name' => 'Unverified Person',
            'birth_year' => 1990,
            'client_category' => 'Regular',
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $this->program->id,
            'track_id' => $this->track->id,
            'alias' => 'U1',
            'client_category' => 'Regular',
            'current_station_id' => $this->station1->id,
            'current_step_order' => 1,
            'status' => 'waiting',
            'identity_registration_id' => $registration->id,
        ]);

        $registration->update(['session_id' => $session->id]);
        $token->update(['current_session_id' => $session->id]);

        $response = $this->actingAs($this->staff)->getJson(
            "/api/stations/{$this->station1->id}/session-by-token?qr_hash=".urlencode($token->qr_code_hash)
        );

        $response->assertStatus(200);
        $response->assertJsonPath('session_id', $session->id);
        $response->assertJsonPath('unverified', true);
    }

    public function test_session_by_token_not_found_returns_404(): void
    {
        $response = $this->actingAs($this->staff)->getJson(
            "/api/stations/{$this->station1->id}/session-by-token?qr_hash=".urlencode('nonexistent')
        );

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Token not found.');
    }

    private function createToken(string $physicalId): Token
    {
        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).$physicalId);
        $token->physical_id = $physicalId;
        $token->status = 'available';
        $token->save();

        return $token;
    }
}
