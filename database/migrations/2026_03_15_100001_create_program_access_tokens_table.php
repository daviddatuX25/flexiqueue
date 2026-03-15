<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per addition-to-public-site-plan Part 2.3: temporary program access tokens for private programs.
     */
    public function up(): void
    {
        Schema::create('program_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->string('token_hash');
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_access_tokens');
    }
};
