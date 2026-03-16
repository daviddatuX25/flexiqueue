<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per 04-DATA-MODEL.md Table 6 — queue client journey.
     * Named 'queue_sessions' to avoid conflict with Laravel's 'sessions' (HTTP).
     */
    public function up(): void
    {
        Schema::create('queue_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('token_id')->constrained('tokens');
            $table->foreignId('program_id')->constrained('programs')->restrictOnDelete();
            $table->foreignId('track_id')->constrained('service_tracks')->restrictOnDelete();
            $table->string('alias', 10);
            $table->string('client_category', 50)->nullable();
            $table->foreignId('current_station_id')->nullable()->constrained('stations');
            $table->unsignedInteger('current_step_order')->nullable();
            $table->enum('status', ['waiting', 'serving', 'completed', 'cancelled', 'no_show'])->default('waiting');
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('no_show_attempts')->default(0);
            $table->timestamps();

            $table->index(['status', 'current_station_id'], 'idx_queue_sessions_active');
            $table->index(['program_id', 'status'], 'idx_queue_sessions_program');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_sessions');
    }
};
