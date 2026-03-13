<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per central-edge-v2-final §Phase C — Token–Program Association.
 * Pivot table: many-to-many between programs and tokens (SQLite + MariaDB).
 * FK delete: cascade — deleting a program or token removes pivot rows (no orphans).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_token', function (Blueprint $table) {
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->foreignId('token_id')->constrained('tokens')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable()->useCurrent();

            $table->primary(['program_id', 'token_id'], 'program_token_primary');
            $table->index('token_id'); // Reverse lookups: Token::programs()
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_token');
    }
};
