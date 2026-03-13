<?php

namespace Tests\Unit\Models;

use App\Models\Program;
use App\Models\Token;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per central-edge C.2: Program::tokens() and Token::programs() many-to-many.
 * Asserts attach/detach, counts, and that token status is unchanged by pivot changes.
 */
class ProgramTokenRelationshipTest extends TestCase
{
    use RefreshDatabase;

    private function createProgram(): Program
    {
        $user = User::factory()->admin()->create();

        return Program::create([
            'site_id' => null,
            'name' => 'Test Program',
            'description' => null,
            'is_active' => false,
            'created_by' => $user->id,
        ]);
    }

    private function createToken(string $physicalId = 'A1'): Token
    {
        $token = new Token;
        $token->qr_code_hash = hash('sha256', 'rel-' . uniqid());
        $token->physical_id = $physicalId;
        $token->status = 'available';
        $token->save();

        return $token;
    }

    public function test_attach_token_to_program_both_sides_see_association(): void
    {
        $program = $this->createProgram();
        $token = $this->createToken();

        $program->tokens()->attach($token->id);

        $program->refresh();
        $token->refresh();

        $this->assertTrue($program->tokens->contains($token));
        $this->assertTrue($token->programs->contains($program));
        $this->assertCount(1, $program->tokens);
        $this->assertCount(1, $token->programs);
    }

    public function test_attach_same_token_to_second_program_token_has_two_programs(): void
    {
        $programA = $this->createProgram();
        $programB = Program::create([
            'site_id' => null,
            'name' => 'Second Program',
            'description' => null,
            'is_active' => false,
            'created_by' => $programA->created_by,
        ]);
        $token = $this->createToken();

        $programA->tokens()->attach($token->id);
        $programB->tokens()->attach($token->id);

        $token->refresh();

        $this->assertCount(2, $token->programs);
        $this->assertTrue($token->programs->contains($programA));
        $this->assertTrue($token->programs->contains($programB));
        $this->assertCount(1, $programA->fresh()->tokens);
        $this->assertCount(1, $programB->fresh()->tokens);
    }

    public function test_detach_from_one_program_assert_counts(): void
    {
        $programA = $this->createProgram();
        $user = User::factory()->admin()->create();
        $programB = Program::create([
            'site_id' => null,
            'name' => 'Second Program',
            'description' => null,
            'is_active' => false,
            'created_by' => $user->id,
        ]);
        $token = $this->createToken();

        $programA->tokens()->attach($token->id);
        $programB->tokens()->attach($token->id);
        $this->assertCount(2, $token->fresh()->programs);

        $programA->tokens()->detach($token->id);

        $programA->refresh();
        $programB->refresh();
        $token->refresh();

        $this->assertCount(0, $programA->tokens);
        $this->assertCount(1, $programB->tokens);
        $this->assertCount(1, $token->programs);
        $this->assertTrue($token->programs->contains($programB));
    }

    public function test_attach_detach_does_not_change_token_status(): void
    {
        $program = $this->createProgram();
        $token = $this->createToken();
        $token->status = 'serving';
        $token->save();

        $program->tokens()->attach($token->id);
        $token->refresh();
        $this->assertSame('serving', $token->status);

        $program->tokens()->detach($token->id);
        $token->refresh();
        $this->assertSame('serving', $token->status);
    }
}
