<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Program;
use App\Models\Site;
use App\Models\Token;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Per central-edge C.3: Admin API for assigning/unassigning tokens to programs.
 * Assign single (or multiple by ids), unassign, bulk by pattern, list; site-scoped 404.
 */
class ProgramTokenAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Program $program;

    private Site $site;

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
        $this->admin = User::factory()->admin()->create(['site_id' => $this->site->id]);
        $this->program = Program::create([
            'site_id' => $this->site->id,
            'name' => 'Test Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
    }

    private function createToken(string $physicalId = 'A1'): Token
    {
        $token = new Token;
        $token->qr_code_hash = hash('sha256', 'test-'.uniqid());
        $token->physical_id = $physicalId;
        $token->status = 'available';
        $token->save();

        return $token;
    }

    public function test_assign_single_token_returns_201_and_token_in_program(): void
    {
        $token = $this->createToken('T1');

        $response = $this->actingAs($this->admin)->postJson("/api/admin/programs/{$this->program->id}/tokens", [
            'token_id' => $token->id,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('token_ids', [$token->id]);
        $this->assertDatabaseHas('program_token', [
            'program_id' => $this->program->id,
            'token_id' => $token->id,
        ]);
        $this->program->refresh();
        $this->assertTrue($this->program->tokens->contains($token));
    }

    public function test_assign_with_token_ids_array_returns_201(): void
    {
        $t1 = $this->createToken('T1');
        $t2 = $this->createToken('T2');

        $response = $this->actingAs($this->admin)->postJson("/api/admin/programs/{$this->program->id}/tokens", [
            'token_ids' => [$t1->id, $t2->id],
        ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['token_ids' => [$t1->id, $t2->id]]);
        $this->assertDatabaseHas('program_token', ['program_id' => $this->program->id, 'token_id' => $t1->id]);
        $this->assertDatabaseHas('program_token', ['program_id' => $this->program->id, 'token_id' => $t2->id]);
    }

    public function test_assign_idempotent_second_call_succeeds_no_duplicate(): void
    {
        $token = $this->createToken('T1');
        $this->program->tokens()->attach($token->id);

        $response = $this->actingAs($this->admin)->postJson("/api/admin/programs/{$this->program->id}/tokens", [
            'token_id' => $token->id,
        ]);

        $response->assertSuccessful(); // 200 or 201 per spec
        $this->program->refresh();
        $this->assertCount(1, $this->program->tokens);
    }

    public function test_unassign_returns_204_and_detaches_token(): void
    {
        $token = $this->createToken('T1');
        $this->program->tokens()->attach($token->id);

        $response = $this->actingAs($this->admin)->deleteJson("/api/admin/programs/{$this->program->id}/tokens/{$token->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('program_token', [
            'program_id' => $this->program->id,
            'token_id' => $token->id,
        ]);
    }

    public function test_unassign_idempotent_when_token_not_in_program_returns_204(): void
    {
        $token = $this->createToken('T1');

        $response = $this->actingAs($this->admin)->deleteJson("/api/admin/programs/{$this->program->id}/tokens/{$token->id}");

        $response->assertStatus(204);
    }

    public function test_bulk_assign_by_pattern_matches_tokens_and_returns_count(): void
    {
        $this->createToken('A1');
        $this->createToken('A2');
        $this->createToken('A3');
        $this->createToken('B1');

        $response = $this->actingAs($this->admin)->postJson("/api/admin/programs/{$this->program->id}/tokens/bulk", [
            'pattern' => 'A*',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('count', 3);
        $ids = $response->json('token_ids');
        $this->assertCount(3, $ids);
        $physicalIds = Token::whereIn('id', $ids)->pluck('physical_id')->sort()->values()->all();
        $this->assertSame(['A1', 'A2', 'A3'], $physicalIds);
    }

    public function test_bulk_assign_idempotent_second_call_returns_same_count(): void
    {
        $this->createToken('A1');
        $this->createToken('A2');

        $this->actingAs($this->admin)->postJson("/api/admin/programs/{$this->program->id}/tokens/bulk", [
            'pattern' => 'A*',
        ])->assertStatus(200)->assertJsonPath('count', 2);

        $response = $this->actingAs($this->admin)->postJson("/api/admin/programs/{$this->program->id}/tokens/bulk", [
            'pattern' => 'A*',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('count', 2);
        $this->program->refresh();
        $this->assertCount(2, $this->program->tokens);
    }

    public function test_bulk_assign_pattern_zero_matches_returns_200_count_zero(): void
    {
        $this->createToken('B1');

        $response = $this->actingAs($this->admin)->postJson("/api/admin/programs/{$this->program->id}/tokens/bulk", [
            'pattern' => 'A*',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('count', 0);
        $response->assertJsonPath('token_ids', []);
    }

    public function test_list_tokens_returns_assigned_tokens(): void
    {
        $t1 = $this->createToken('A1');
        $t2 = $this->createToken('A2');
        $this->program->tokens()->attach([$t1->id, $t2->id]);

        $response = $this->actingAs($this->admin)->getJson("/api/admin/programs/{$this->program->id}/tokens");

        $response->assertStatus(200);
        $response->assertJsonStructure(['tokens' => [['id', 'physical_id', 'status', 'tts_status']]]);
        $tokens = $response->json('tokens');
        $this->assertCount(2, $tokens);
        $physicalIds = collect($tokens)->pluck('physical_id')->sort()->values()->all();
        $this->assertSame(['A1', 'A2'], $physicalIds);
    }

    public function test_list_tokens_empty_when_none_assigned(): void
    {
        $response = $this->actingAs($this->admin)->getJson("/api/admin/programs/{$this->program->id}/tokens");

        $response->assertStatus(200);
        $response->assertJsonPath('tokens', []);
    }

    public function test_404_for_wrong_site_program(): void
    {
        $siteB = Site::create([
            'name' => 'Site B',
            'slug' => 'site-b',
            'api_key_hash' => \Illuminate\Support\Facades\Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $programB = Program::create([
            'site_id' => $siteB->id,
            'name' => 'Program B',
            'description' => null,
            'is_active' => false,
            'created_by' => User::factory()->admin()->create(['site_id' => $siteB->id])->id,
        ]);
        $token = $this->createToken('T1');

        $this->actingAs($this->admin)->getJson("/api/admin/programs/{$programB->id}/tokens")->assertStatus(404);
        $this->actingAs($this->admin)->postJson("/api/admin/programs/{$programB->id}/tokens", [
            'token_id' => $token->id,
        ])->assertStatus(404);
        $this->actingAs($this->admin)->postJson("/api/admin/programs/{$programB->id}/tokens/bulk", [
            'pattern' => 'A*',
        ])->assertStatus(404);
        $this->actingAs($this->admin)->deleteJson("/api/admin/programs/{$programB->id}/tokens/{$token->id}")->assertStatus(404);
    }

    public function test_assign_validation_requires_token_id_or_token_ids(): void
    {
        $response = $this->actingAs($this->admin)->postJson("/api/admin/programs/{$this->program->id}/tokens", []);

        $response->assertStatus(422);
    }

    public function test_bulk_validation_requires_pattern(): void
    {
        $response = $this->actingAs($this->admin)->postJson("/api/admin/programs/{$this->program->id}/tokens/bulk", []);

        $response->assertStatus(422);
    }
}
