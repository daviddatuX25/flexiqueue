<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per 04-DATA-MODEL.md Table 5.
     * current_session_id FK added after sessions table exists (see 2025_02_14_000010).
     */
    public function up(): void
    {
        Schema::create('tokens', function (Blueprint $table) {
            $table->id();
            $table->string('qr_code_hash', 64);
            $table->string('physical_id', 10);
            $table->enum('status', ['available', 'in_use', 'lost', 'damaged'])->default('available');
            $table->unsignedBigInteger('current_session_id')->nullable();
            $table->timestamps();

            $table->unique('qr_code_hash', 'idx_tokens_hash');
            $table->index('physical_id', 'idx_tokens_physical');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tokens');
    }
};
