<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Program;
use App\Models\ServiceTrack;
use App\Models\Session;
use App\Models\Site;
use App\Models\Token;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase C.4 — Verification: token in multiple programs; no side effects on status or sessions.
 */
class ProgramTokenSideEffectsTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::create([
            'name' => 'Default Site',
            'slug' => 'default',
            'api_key_hash' => \Illuminate\Support\Facades\Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);

        $this->admin = User::factory()->admin()->create([
            'site_id' => $this->site->id,
        ]);
    }

    private function createProgram(string $name): Program
    {
        return Program::create([
            'site_id' => $this->site->id,
            'name' => $name,
            'description' => null,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
    }

    private function createTrack(Program $program, string $name = 'Regular'): ServiceTrack
    {
        return ServiceTrack::create([
            'program_id' => $program->id,
            'name' => $name,
            'description' => null,
            'is_default' => true,
            'color_code' => null,
        ]);
    }

    private function createToken(string $physicalId = 'A1', string $status = 'available'): Token
    {
        $token = new Token;
        $token->qr_code_hash = hash('sha256', 'side-effects-' . uniqid());
        $token->physical_id = $physicalId;
        $token->status = $status;
        $token->save();

        return $token;
    }

    public function test_assigning_token_to_second_program_does_not_change_existing_sessions_or_status(): void
    {
        $programA = $this->createProgram('Program A');
        $programB = $this->createProgram('Program B');
        $trackA = $this->createTrack($programA);
        $token = $this->createToken('TA1', 'waiting');

        // Attach token to Program A and create a session bound to Program A.
        $programA->tokens()->attach($token->id);

        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $programA->id,
            'track_id' => $trackA->id,
            'alias' => 'A-' . $token->id,
            'client_category' => 'Regular',
            'current_station_id' => null,
            'current_step_order' => 1,
            'status' => 'waiting',
            'no_show_attempts' => 0,
        ]);

        $this->assertSame('waiting', $session->status);
        $this->assertSame('waiting', $token->fresh()->status);

        // Use admin API to assign token to Program B (second program).
        $response = $this->actingAs($this->admin)->postJson(
            "/api/admin/programs/{$programB->id}/tokens",
            ['token_id' => $token->id]
        );

        $response->assertStatus(201);

        // Session row is unchanged.
        $this->assertDatabaseHas('queue_sessions', [
            'id' => $session->id,
            'program_id' => $programA->id,
            'token_id' => $token->id,
            'status' => 'waiting',
        ]);

        // Token status is unchanged.
        $this->assertSame('waiting', $token->fresh()->status);

        // Token is assigned to one program only: assigning to B replaced A (no multi-program assignment).
        $this->assertDatabaseMissing('program_token', [
            'program_id' => $programA->id,
            'token_id' => $token->id,
        ]);
        $this->assertDatabaseHas('program_token', [
            'program_id' => $programB->id,
            'token_id' => $token->id,
        ]);
    }

    public function test_unassigning_token_from_one_program_does_not_delete_sessions_or_change_status(): void
    {
        $programA = $this->createProgram('Program A');
        $programB = $this->createProgram('Program B');
        $trackA = $this->createTrack($programA);
        $token = $this->createToken('TB1', 'serving');

        // Attach token to both programs and create a session bound to Program A.
        $programA->tokens()->attach($token->id);
        $programB->tokens()->attach($token->id);

        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $programA->id,
            'track_id' => $trackA->id,
            'alias' => 'B-' . $token->id,
            'client_category' => 'Regular',
            'current_station_id' => null,
            'current_step_order' => 1,
            'status' => 'serving',
            'no_show_attempts' => 0,
        ]);

        $this->assertSame('serving', $session->status);
        $this->assertSame('serving', $token->fresh()->status);

        // Unassign token from Program A only.
        $response = $this->actingAs($this->admin)->deleteJson(
            "/api/admin/programs/{$programA->id}/tokens/{$token->id}"
        );

        $response->assertStatus(204);

        // Session row is unchanged and still bound to Program A and this token.
        $this->assertDatabaseHas('queue_sessions', [
            'id' => $session->id,
            'program_id' => $programA->id,
            'token_id' => $token->id,
            'status' => 'serving',
        ]);

        // Token status is unchanged.
        $this->assertSame('serving', $token->fresh()->status);

        // Pivot row for Program A is removed, Program B remains.
        $this->assertDatabaseMissing('program_token', [
            'program_id' => $programA->id,
            'token_id' => $token->id,
        ]);
        $this->assertDatabaseHas('program_token', [
            'program_id' => $programB->id,
            'token_id' => $token->id,
        ]);
    }
}

