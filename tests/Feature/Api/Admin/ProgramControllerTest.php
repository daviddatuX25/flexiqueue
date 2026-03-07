<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Process;
use App\Models\Program;
use App\Models\ProgramAuditLog;
use App\Models\ProgramStationAssignment;
use App\Models\ServiceTrack;
use App\Models\Session;
use App\Models\Station;
use App\Models\Token;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per 08-API-SPEC-PHASE1 §5.1: Program CRUD + activate/deactivate. All require role:admin.
 */
class ProgramControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    public function test_index_returns_all_programs(): void
    {
        Program::create([
            'name' => 'Program A',
            'description' => 'Desc A',
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/programs');

        $response->assertStatus(200);
        $response->assertJsonPath('programs.0.name', 'Program A');
    }

    public function test_store_creates_program_returns_201(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/admin/programs', [
            'name' => 'Cash Assistance',
            'description' => 'Q1 2026',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('program.name', 'Cash Assistance');
        $response->assertJsonPath('program.is_active', false);
        $this->assertDatabaseHas('programs', ['name' => 'Cash Assistance']);
    }

    public function test_store_validates_name_required(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/admin/programs', [
            'name' => '',
            'description' => 'Ok',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('name');
    }

    public function test_update_modifies_program(): void
    {
        $program = Program::create([
            'name' => 'Old',
            'description' => 'Old desc',
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->putJson("/api/admin/programs/{$program->id}", [
            'name' => 'New Name',
            'description' => 'New desc',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('program.name', 'New Name');
        $program->refresh();
        $this->assertSame('New Name', $program->name);
    }

    public function test_update_tts_returns_requires_regeneration_when_stations_have_generated_tts(): void
    {
        $program = Program::create([
            'name' => 'P',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'S1',
            'capacity' => 1,
            'is_active' => true,
            'settings' => [
                'tts' => [
                    'languages' => [
                        'en' => ['status' => 'ready', 'audio_path' => 'tts/stations/1/en.mp3'],
                    ],
                ],
            ],
        ]);

        $response = $this->actingAs($this->admin)->putJson("/api/admin/programs/{$program->id}", [
            'name' => 'P',
            'description' => null,
            'settings' => [
                'tts' => [
                    'active_language' => 'en',
                    'connector' => [
                        'languages' => [
                            'en' => ['voice_id' => null, 'rate' => 0.84, 'connector_phrase' => 'Please proceed to'],
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('requires_regeneration', true);
    }

    public function test_regenerate_station_tts_returns_200(): void
    {
        $program = Program::create([
            'name' => 'P',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);
        Station::create([
            'program_id' => $program->id,
            'name' => 'S1',
            'capacity' => 1,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->postJson("/api/admin/programs/{$program->id}/regenerate-station-tts");

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Station TTS regeneration started.');
    }

    public function test_update_merges_program_settings(): void
    {
        $program = Program::create([
            'name' => 'P',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
            'settings' => ['no_show_timer_seconds' => 15],
        ]);

        $response = $this->actingAs($this->admin)->putJson("/api/admin/programs/{$program->id}", [
            'name' => 'P',
            'description' => null,
            'settings' => [
                'require_permission_before_override' => false,
            ],
        ]);

        $response->assertStatus(200);
        $program->refresh();
        $settings = $program->settings ?? [];
        $this->assertSame(15, $settings['no_show_timer_seconds']);
        $this->assertFalse($settings['require_permission_before_override']);
    }

    /** Per plan: display_audio_volume must be 0-1; invalid value returns 422. */
    public function test_update_rejects_display_audio_volume_out_of_range(): void
    {
        $program = Program::create([
            'name' => 'P',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->putJson("/api/admin/programs/{$program->id}", [
            'name' => 'P',
            'description' => null,
            'settings' => ['display_audio_volume' => 1.5],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['settings.display_audio_volume']);
    }

    /** Per plan: valid display_audio_muted and display_audio_volume are persisted. */
    public function test_update_persists_display_audio_settings(): void
    {
        $program = Program::create([
            'name' => 'P',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->putJson("/api/admin/programs/{$program->id}", [
            'name' => 'P',
            'description' => null,
            'settings' => [
                'display_audio_muted' => true,
                'display_audio_volume' => 0.5,
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('program.settings.display_audio_muted', true);
        $response->assertJsonPath('program.settings.display_audio_volume', 0.5);
        $program->refresh();
        $this->assertTrue($program->getDisplayAudioMuted());
        $this->assertSame(0.5, $program->getDisplayAudioVolume());
    }

    /** Per plan: display_tts_repeat_count and display_tts_repeat_delay_ms are persisted (1–3, 500–10000 ms). */
    public function test_update_persists_display_tts_repeat_settings(): void
    {
        $program = Program::create([
            'name' => 'P',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->putJson("/api/admin/programs/{$program->id}", [
            'name' => 'P',
            'description' => null,
            'settings' => [
                'display_tts_repeat_count' => 2,
                'display_tts_repeat_delay_ms' => 3000,
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('program.settings.display_tts_repeat_count', 2);
        $response->assertJsonPath('program.settings.display_tts_repeat_delay_ms', 3000);
        $program->refresh();
        $this->assertSame(2, $program->getDisplayTtsRepeatCount());
        $this->assertSame(3000, $program->getDisplayTtsRepeatDelayMs());
    }

    /** Per plan: display_tts_repeat_count rejects values outside 1–3. */
    public function test_update_rejects_display_tts_repeat_count_out_of_range(): void
    {
        $program = Program::create([
            'name' => 'P',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->putJson("/api/admin/programs/{$program->id}", [
            'name' => 'P',
            'description' => null,
            'settings' => ['display_tts_repeat_count' => 5],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['settings.display_tts_repeat_count']);
    }

    /** Per plan: display_tts_repeat_delay_ms rejects values outside 500–10000. */
    public function test_update_rejects_display_tts_repeat_delay_ms_out_of_range(): void
    {
        $program = Program::create([
            'name' => 'P',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->putJson("/api/admin/programs/{$program->id}", [
            'name' => 'P',
            'description' => null,
            'settings' => ['display_tts_repeat_delay_ms' => 100],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['settings.display_tts_repeat_delay_ms']);
    }

    public function test_update_persists_station_selection_mode(): void
    {
        $program = Program::create([
            'name' => 'P',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->putJson("/api/admin/programs/{$program->id}", [
            'name' => 'P',
            'description' => null,
            'settings' => ['station_selection_mode' => 'shortest_queue'],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('program.settings.station_selection_mode', 'shortest_queue');
        $program->refresh();
        $this->assertSame('shortest_queue', $program->getStationSelectionMode());
    }

    /** Per bead flexiqueue-5gl: program settings accept and persist alternate_priority_first. */
    public function test_update_accepts_alternate_priority_first_setting(): void
    {
        $program = Program::create([
            'name' => 'P',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
            'settings' => ['balance_mode' => 'alternate'],
        ]);

        $response = $this->actingAs($this->admin)->putJson("/api/admin/programs/{$program->id}", [
            'name' => 'P',
            'description' => null,
            'settings' => [
                'balance_mode' => 'alternate',
                'alternate_priority_first' => false,
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('program.settings.alternate_priority_first', false);
        $program->refresh();
        $this->assertFalse($program->settings['alternate_priority_first'] ?? true);

        $response2 = $this->actingAs($this->admin)->putJson("/api/admin/programs/{$program->id}", [
            'name' => 'P',
            'description' => null,
            'settings' => ['alternate_priority_first' => true],
        ]);
        $response2->assertStatus(200);
        $program->refresh();
        $this->assertTrue($program->settings['alternate_priority_first'] ?? false);
    }

    /** Per ISSUES-ELABORATION §16: activate returns 422 when pre-session checks fail. */
    public function test_activate_returns_422_when_missing_required_config(): void
    {
        $program = Program::create([
            'name' => 'Empty',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->postJson("/api/admin/programs/{$program->id}/activate");

        $response->assertStatus(422);
        $missing = $response->json('missing');
        $this->assertContains('no_stations', $missing);
        $this->assertContains('no_tracks', $missing);
    }

    public function test_activate_sets_program_active_and_deactivates_others(): void
    {
        $other = Program::create([
            'name' => 'Other',
            'description' => null,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        $program = Program::create([
            'name' => 'To Activate',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);
        $this->addMinimalActivateSetup($program);

        $response = $this->actingAs($this->admin)->postJson("/api/admin/programs/{$program->id}/activate");

        $response->assertStatus(200);
        $response->assertJsonPath('program.is_active', true);
        $this->assertTrue($program->fresh()->is_active);
        $this->assertFalse($other->fresh()->is_active);

        $this->assertDatabaseHas('program_audit_log', [
            'program_id' => $other->id,
            'staff_user_id' => $this->admin->id,
            'action' => 'session_stop',
        ]);
        $this->assertDatabaseHas('program_audit_log', [
            'program_id' => $program->id,
            'staff_user_id' => $this->admin->id,
            'action' => 'session_start',
        ]);
    }

    public function test_activate_fails_when_another_program_has_active_sessions(): void
    {
        $runningProgram = Program::create([
            'name' => 'Running Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        $track = ServiceTrack::create([
            'program_id' => $runningProgram->id,
            'name' => 'Track 1',
            'is_default' => true,
        ]);
        $station = Station::create([
            'program_id' => $runningProgram->id,
            'name' => 'Station 1',
            'capacity' => 1,
        ]);
        $token = new Token;
        $token->qr_code_hash = str_repeat('a', 64);
        $token->physical_id = 'A1';
        $token->status = 'in_use';
        $token->save();
        Session::create([
            'token_id' => $token->id,
            'program_id' => $runningProgram->id,
            'track_id' => $track->id,
            'alias' => 'A1',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'status' => 'waiting',
            'no_show_attempts' => 0,
        ]);

        $otherProgram = Program::create([
            'name' => 'Other Program',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);
        $this->addMinimalActivateSetup($otherProgram);

        $response = $this->actingAs($this->admin)->postJson("/api/admin/programs/{$otherProgram->id}/activate");

        $response->assertStatus(409);
        $response->assertJsonFragment(['message' => 'Another program is currently running and has clients in the queue. Stop that program\'s session first (or wait until the queue is empty) before starting this program.']);
        $this->assertTrue($runningProgram->fresh()->is_active);
        $this->assertFalse($otherProgram->fresh()->is_active);
    }

    public function test_activate_creates_session_start_log_when_no_previous_active(): void
    {
        $program = Program::create([
            'name' => 'First',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);
        $this->addMinimalActivateSetup($program);

        $this->actingAs($this->admin)->postJson("/api/admin/programs/{$program->id}/activate");

        $this->assertDatabaseHas('program_audit_log', [
            'program_id' => $program->id,
            'staff_user_id' => $this->admin->id,
            'action' => 'session_start',
        ]);
        $this->assertDatabaseCount('program_audit_log', 1);
    }

    public function test_activate_syncs_assigned_station_id_from_program_station_assignments(): void
    {
        $staff = User::factory()->create(['role' => 'staff', 'assigned_station_id' => null]);
        $program = Program::create([
            'name' => 'To Activate',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'Verification',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $process = Process::create([
            'program_id' => $program->id,
            'name' => 'P1',
            'description' => null,
        ]);
        DB::table('station_process')->insert([
            'station_id' => $station->id,
            'process_id' => $process->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        ProgramStationAssignment::create([
            'program_id' => $program->id,
            'user_id' => $staff->id,
            'station_id' => $station->id,
        ]);
        ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'T1',
            'is_default' => true,
        ]);

        $this->actingAs($this->admin)->postJson("/api/admin/programs/{$program->id}/activate");

        $staff->refresh();
        $this->assertSame($station->id, $staff->assigned_station_id);
    }

    public function test_deactivate_fails_when_active_sessions_exist(): void
    {
        $program = Program::create([
            'name' => 'P',
            'description' => null,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        $track = ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Track 1',
            'is_default' => true,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'Station 1',
            'capacity' => 1,
        ]);
        $token = new Token;
        $token->qr_code_hash = str_repeat('a', 64);
        $token->physical_id = 'A1';
        $token->status = 'in_use';
        $token->save();
        Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'A1',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'status' => 'waiting',
            'no_show_attempts' => 0,
        ]);

        $response = $this->actingAs($this->admin)->postJson("/api/admin/programs/{$program->id}/deactivate");

        $response->assertStatus(400);
        $response->assertJsonFragment(['message' => 'Cannot deactivate: program has active sessions.']);
    }

    public function test_deactivate_succeeds_when_no_active_sessions(): void
    {
        $program = Program::create([
            'name' => 'P',
            'description' => null,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->postJson("/api/admin/programs/{$program->id}/deactivate");

        $response->assertStatus(200);
        $this->assertFalse($program->fresh()->is_active);
        $this->assertDatabaseHas('program_audit_log', [
            'program_id' => $program->id,
            'staff_user_id' => $this->admin->id,
            'action' => 'session_stop',
        ]);
    }

    public function test_destroy_fails_when_sessions_exist(): void
    {
        $program = Program::create([
            'name' => 'P',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);
        $track = ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Track 1',
            'is_default' => true,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'Station 1',
            'capacity' => 1,
        ]);
        $token = new Token;
        $token->qr_code_hash = str_repeat('b', 64);
        $token->physical_id = 'B1';
        $token->status = 'available';
        $token->save();
        Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'B1',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'status' => 'completed',
            'no_show_attempts' => 0,
        ]);

        $response = $this->actingAs($this->admin)->deleteJson("/api/admin/programs/{$program->id}");

        $response->assertStatus(400);
        $this->assertDatabaseHas('programs', ['id' => $program->id]);
    }

    public function test_destroy_succeeds_when_no_sessions(): void
    {
        $program = Program::create([
            'name' => 'P',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->deleteJson("/api/admin/programs/{$program->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('programs', ['id' => $program->id]);
    }

    public function test_station_delete_cleans_up_tts_files(): void
    {
        $program = Program::create([
            'name' => 'P',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);

        \Illuminate\Support\Facades\Storage::fake('local');

        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'Desk 1',
            'capacity' => 1,
            'is_active' => true,
            'settings' => [
                'tts' => [
                    'languages' => [
                        'en' => ['audio_path' => 'tts/stations/1/en.mp3', 'status' => 'ready'],
                    ],
                ],
            ],
        ]);

        \Illuminate\Support\Facades\Storage::put('tts/stations/'.$station->id.'/en.mp3', 'audio');

        $response = $this->actingAs($this->admin)->deleteJson("/api/admin/stations/{$station->id}");

        $response->assertStatus(204);
        $this->assertSoftDeleted('stations', ['id' => $station->id]);
        \Illuminate\Support\Facades\Storage::assertMissing('tts/stations/'.$station->id.'/en.mp3');
    }

    public function test_pause_sets_program_paused(): void
    {
        $program = Program::create([
            'name' => 'P',
            'description' => null,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->postJson("/api/admin/programs/{$program->id}/pause");

        $response->assertStatus(200);
        $response->assertJsonPath('program.is_paused', true);
        $this->assertTrue($program->fresh()->is_paused);
    }

    public function test_resume_clears_program_paused(): void
    {
        $program = Program::create([
            'name' => 'P',
            'description' => null,
            'is_active' => true,
            'is_paused' => true,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->postJson("/api/admin/programs/{$program->id}/resume");

        $response->assertStatus(200);
        $response->assertJsonPath('program.is_paused', false);
        $this->assertFalse($program->fresh()->is_paused);
    }

    public function test_staff_cannot_access_programs_api_returns_403(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        $response = $this->actingAs($staff)->getJson('/api/admin/programs');

        $response->assertStatus(403);
    }

    /**
     * Per ISSUES-ELABORATION §16: minimal setup so activate() passes pre-session checks.
     */
    private function addMinimalActivateSetup(Program $program): void
    {
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'S1',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $process = Process::create([
            'program_id' => $program->id,
            'name' => 'P1',
            'description' => null,
        ]);
        DB::table('station_process')->insert([
            'station_id' => $station->id,
            'process_id' => $process->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        ProgramStationAssignment::create([
            'program_id' => $program->id,
            'user_id' => $this->admin->id,
            'station_id' => $station->id,
        ]);
        ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'T1',
            'is_default' => true,
        ]);
    }
}
