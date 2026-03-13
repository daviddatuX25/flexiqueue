<?php

namespace Tests\Feature\Migrations;

use App\Models\Program;
use App\Models\Token;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Per central-edge C.1: program_token pivot table (SQLite + MariaDB).
 * Asserts table exists, columns, composite PK, and FK behavior.
 */
class ProgramTokenMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_program_token_table_exists_with_spec_columns(): void
    {
        $this->assertTrue(Schema::hasTable('program_token'));

        $columns = Schema::getColumnListing('program_token');
        $this->assertContains('program_id', $columns);
        $this->assertContains('token_id', $columns);
        $this->assertContains('created_at', $columns);
    }

    public function test_composite_primary_key_prevents_duplicate_program_token_pairs(): void
    {
        $user = User::factory()->admin()->create();
        $program = Program::create([
            'site_id' => null,
            'name' => 'Test Program',
            'description' => null,
            'is_active' => false,
            'created_by' => $user->id,
        ]);

        $token = new Token;
        $token->qr_code_hash = hash('sha256', 'unique-' . uniqid());
        $token->physical_id = 'A1';
        $token->status = 'available';
        $token->save();

        DB::table('program_token')->insert([
            'program_id' => $program->id,
            'token_id' => $token->id,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('program_token')->insert([
            'program_id' => $program->id,
            'token_id' => $token->id,
        ]);
    }

    public function test_valid_program_id_and_token_id_insert_succeeds(): void
    {
        $user = User::factory()->admin()->create();
        $program = Program::create([
            'site_id' => null,
            'name' => 'Test Program',
            'description' => null,
            'is_active' => false,
            'created_by' => $user->id,
        ]);

        $token = new Token;
        $token->qr_code_hash = hash('sha256', 'valid-' . uniqid());
        $token->physical_id = 'B1';
        $token->status = 'available';
        $token->save();

        DB::table('program_token')->insert([
            'program_id' => $program->id,
            'token_id' => $token->id,
        ]);

        $this->assertDatabaseHas('program_token', [
            'program_id' => $program->id,
            'token_id' => $token->id,
        ]);
    }

    public function test_invalid_program_id_rejected_by_foreign_key(): void
    {
        $token = new Token;
        $token->qr_code_hash = hash('sha256', 'orphan-' . uniqid());
        $token->physical_id = 'C1';
        $token->status = 'available';
        $token->save();

        $nonExistentProgramId = 999999;

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('program_token')->insert([
            'program_id' => $nonExistentProgramId,
            'token_id' => $token->id,
        ]);
    }

    public function test_invalid_token_id_rejected_by_foreign_key(): void
    {
        $user = User::factory()->admin()->create();
        $program = Program::create([
            'site_id' => null,
            'name' => 'Test Program',
            'description' => null,
            'is_active' => false,
            'created_by' => $user->id,
        ]);

        $nonExistentTokenId = 999999;

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('program_token')->insert([
            'program_id' => $program->id,
            'token_id' => $nonExistentTokenId,
        ]);
    }
}
