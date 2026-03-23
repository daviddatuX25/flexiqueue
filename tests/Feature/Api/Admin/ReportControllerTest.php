<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Program;
use App\Models\ProgramAuditLog;
use App\Models\ServiceTrack;
use App\Models\Session;
use App\Models\Site;
use App\Models\Station;
use App\Models\Token;
use App\Models\TrackStep;
use App\Models\TransactionLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Per 08-API-SPEC-PHASE1 §5.8: Audit log API. Auth: role:admin.
 */
class ReportControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staff;

    private Program $program;

    private Station $station;

    private Session $session;

    private TransactionLog $log;

    protected function setUp(): void
    {
        parent::setUp();
        $site = Site::create([
            'name' => 'Default Site',
            'slug' => 'default',
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $this->admin = User::factory()->admin()->create(['site_id' => $site->id]);
        $this->staff = User::factory()->create(['site_id' => $site->id]);
        $this->program = Program::create([
            'site_id' => $site->id,
            'name' => 'Test Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        $this->station = Station::create([
            'program_id' => $this->program->id,
            'name' => 'First Station',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $track = ServiceTrack::create([
            'program_id' => $this->program->id,
            'name' => 'Default',
            'is_default' => true,
            'color_code' => '#333',
        ]);
        TrackStep::create([
            'track_id' => $track->id,
            'station_id' => $this->station->id,
            'step_order' => 1,
            'is_required' => true,
        ]);
        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).'A1');
        $token->physical_id = 'A1';
        $token->status = 'in_use';
        $token->save();
        $this->session = Session::create([
            'token_id' => $token->id,
            'program_id' => $this->program->id,
            'track_id' => $track->id,
            'alias' => 'A1',
            'client_category' => 'regular',
            'current_station_id' => $this->station->id,
            'current_step_order' => 1,
            'status' => 'waiting',
        ]);
        $token->update(['current_session_id' => $this->session->id]);
        $this->log = TransactionLog::create([
            'session_id' => $this->session->id,
            'station_id' => $this->station->id,
            'staff_user_id' => $this->staff->id,
            'action_type' => 'check_in',
            'remarks' => null,
        ]);
    }

    public function test_audit_returns_paginated_logs_for_admin(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/admin/logs/audit');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                [
                    'id', 'session_alias', 'action_type', 'station', 'staff', 'remarks', 'created_at',
                ],
            ],
            'meta' => ['total', 'per_page', 'current_page'],
        ]);
        $response->assertJsonPath('data.0.session_alias', 'A1');
        $response->assertJsonPath('data.0.action_type', 'check_in');
        $response->assertJsonPath('data.0.station', 'First Station');
        $response->assertJsonPath('data.0.staff', $this->staff->name);
        $response->assertJsonPath('meta.total', 1);
    }

    public function test_program_sessions_returns_list_for_admin(): void
    {
        ProgramAuditLog::create([
            'program_id' => $this->program->id,
            'staff_user_id' => $this->admin->id,
            'action' => 'session_start',
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/logs/program-sessions');

        $response->assertStatus(200);
        $response->assertJsonStructure(['program_sessions']);
        $sessions = $response->json('program_sessions');
        $this->assertNotEmpty($sessions);
        $this->assertSame($this->program->id, $sessions[0]['program_id']);
        $this->assertArrayHasKey('started_at', $sessions[0]);
        $this->assertArrayHasKey('ended_at', $sessions[0]);
        $this->assertArrayHasKey('program_name', $sessions[0]);
        $this->assertArrayHasKey('started_by', $sessions[0]);
    }

    public function test_audit_filters_by_staff_user_id(): void
    {
        $otherStaff = User::factory()->create();
        TransactionLog::create([
            'session_id' => $this->session->id,
            'station_id' => $this->station->id,
            'staff_user_id' => $otherStaff->id,
            'action_type' => 'complete',
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/admin/logs/audit?staff_user_id={$this->staff->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.staff', $this->staff->name);
    }

    public function test_audit_includes_program_session_start_stop(): void
    {
        ProgramAuditLog::create([
            'program_id' => $this->program->id,
            'staff_user_id' => $this->admin->id,
            'action' => 'session_start',
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/admin/logs/audit?program_id={$this->program->id}");

        $response->assertStatus(200);
        $data = $response->json('data');
        $types = array_column($data, 'action_type');
        $this->assertContains('session_start', $types);
        $this->assertContains('check_in', $types);
    }

    public function test_audit_filters_by_program_id(): void
    {
        $otherProgram = Program::create([
            'site_id' => $this->program->site_id,
            'name' => 'Other',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);
        $otherStation = Station::create([
            'program_id' => $otherProgram->id,
            'name' => 'Other Station',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $otherTrack = ServiceTrack::create([
            'program_id' => $otherProgram->id,
            'name' => 'Other',
            'is_default' => true,
            'color_code' => '#444',
        ]);
        TrackStep::create([
            'track_id' => $otherTrack->id,
            'station_id' => $otherStation->id,
            'step_order' => 1,
            'is_required' => true,
        ]);
        $otherToken = new Token;
        $otherToken->qr_code_hash = hash('sha256', Str::random(32).'B1');
        $otherToken->physical_id = 'B1';
        $otherToken->status = 'in_use';
        $otherToken->save();
        $otherSession = Session::create([
            'token_id' => $otherToken->id,
            'program_id' => $otherProgram->id,
            'track_id' => $otherTrack->id,
            'alias' => 'B1',
            'client_category' => 'regular',
            'current_station_id' => $otherStation->id,
            'current_step_order' => 1,
            'status' => 'waiting',
        ]);
        $otherToken->update(['current_session_id' => $otherSession->id]);
        TransactionLog::create([
            'session_id' => $otherSession->id,
            'station_id' => $otherStation->id,
            'staff_user_id' => $this->staff->id,
            'action_type' => 'check_in',
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/admin/logs/audit?program_id={$this->program->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.session_alias', 'A1');
    }

    public function test_audit_filters_by_action_type(): void
    {
        TransactionLog::create([
            'session_id' => $this->session->id,
            'station_id' => $this->station->id,
            'staff_user_id' => $this->staff->id,
            'action_type' => 'complete',
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/logs/audit?action_type=complete');

        $response->assertStatus(200);
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.action_type', 'complete');
    }

    public function test_audit_staff_returns_403(): void
    {
        $response = $this->actingAs($this->staff)->getJson('/api/admin/logs/audit');

        $response->assertStatus(403);
    }

    public function test_audit_export_returns_csv_for_admin(): void
    {
        $response = $this->actingAs($this->admin)->get('/api/admin/logs/audit/export');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $content = $response->streamedContent();
        $this->assertStringContainsString('Time,Source,Session,Action,Station,Staff,Remarks', $content);
        $this->assertStringContainsString('check_in', $content);
        $this->assertStringContainsString('A1', $content);
    }

    /** Per flexiqueue-m7z: audit log includes staff_activity_log (availability changes). */
    public function test_audit_includes_staff_activity_log_entries(): void
    {
        DB::table('staff_activity_log')->insert([
            'user_id' => $this->staff->id,
            'action_type' => 'availability_change',
            'old_value' => 'offline',
            'new_value' => 'available',
            'metadata' => null,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/logs/audit');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertIsArray($data);
        $staffActivityEntries = array_filter($data, fn ($e) => ($e['source'] ?? '') === 'staff_activity');
        $this->assertNotEmpty($staffActivityEntries, 'Audit log should include at least one staff_activity entry');
        $first = reset($staffActivityEntries);
        $this->assertSame('staff_activity', $first['source']);
        $this->assertSame('availability_change', $first['action_type']);
        $this->assertSame($this->staff->name, $first['staff']);
        $this->assertStringContainsString('offline', $first['remarks'] ?? '');
        $this->assertStringContainsString('available', $first['remarks'] ?? '');
    }

    public function test_audit_export_staff_returns_403(): void
    {
        $response = $this->actingAs($this->staff)->get('/api/admin/logs/audit/export');

        $response->assertStatus(403);
    }

    /** Per site-scoping-migration-spec §5: site admin with null site_id gets 403. */
    public function test_site_admin_with_null_site_id_gets_403_on_program_sessions(): void
    {
        $adminNoSite = User::factory()->admin()->create(['site_id' => null]);

        $response = $this->actingAs($adminNoSite)->getJson('/api/admin/logs/program-sessions');

        $response->assertStatus(403);
    }

    /** Per site-scoping-migration-spec §5: site admin with null site_id gets 403. */
    public function test_site_admin_with_null_site_id_gets_403_on_audit(): void
    {
        $adminNoSite = User::factory()->admin()->create(['site_id' => null]);

        $response = $this->actingAs($adminNoSite)->getJson('/api/admin/logs/audit');

        $response->assertStatus(403);
    }

    /** Per site-scoping-migration-spec §5: site admin cannot see program sessions from another site. */
    public function test_site_admin_cannot_see_program_sessions_from_other_site(): void
    {
        $siteB = Site::create([
            'name' => 'Site B',
            'slug' => 'site-b',
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $adminB = User::factory()->admin()->create(['site_id' => $siteB->id]);
        $programB = Program::create([
            'site_id' => $siteB->id,
            'name' => 'Program B',
            'description' => null,
            'is_active' => true,
            'created_by' => $adminB->id,
        ]);
        ProgramAuditLog::create([
            'program_id' => $programB->id,
            'staff_user_id' => $adminB->id,
            'action' => 'session_start',
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/logs/program-sessions');

        $response->assertStatus(200);
        $sessions = $response->json('program_sessions');
        $programIds = collect($sessions)->pluck('program_id')->unique()->values()->all();
        $this->assertNotContains($programB->id, $programIds);
    }

    /** Per site-scoping-migration-spec §5: site admin cannot see audit for program in another site. */
    public function test_site_admin_cannot_see_audit_log_for_other_site_program(): void
    {
        $siteB = Site::create([
            'name' => 'Site B',
            'slug' => 'site-b',
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $programB = Program::create([
            'site_id' => $siteB->id,
            'name' => 'Program B',
            'description' => null,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/admin/logs/audit?program_id={$programB->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('meta.total', 0);
    }

    /** Per site-scoping-migration-spec §5: program_session_id from other site returns empty. */
    public function test_program_session_id_from_other_site_returns_empty_audit(): void
    {
        $siteB = Site::create([
            'name' => 'Site B',
            'slug' => 'site-b',
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $adminB = User::factory()->admin()->create(['site_id' => $siteB->id]);
        $programB = Program::create([
            'site_id' => $siteB->id,
            'name' => 'Program B',
            'description' => null,
            'is_active' => true,
            'created_by' => $adminB->id,
        ]);
        $pal = ProgramAuditLog::create([
            'program_id' => $programB->id,
            'staff_user_id' => $adminB->id,
            'action' => 'session_start',
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/admin/logs/audit?program_session_id={$pal->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('meta.total', 0);
    }
}
