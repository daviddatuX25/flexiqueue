<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per 04-DATA-MODEL.md Table 7 — immutable audit trail.
     */
    public function up(): void
    {
        Schema::create('transaction_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('queue_sessions')->restrictOnDelete();
            $table->foreignId('station_id')->nullable()->constrained('stations');
            $table->foreignId('staff_user_id')->constrained('users');
            $table->enum('action_type', [
                'bind', 'check_in', 'transfer', 'override',
                'complete', 'cancel', 'no_show', 'force_complete', 'identity_mismatch'
            ]);
            $table->foreignId('previous_station_id')->nullable()->constrained('stations');
            $table->foreignId('next_station_id')->nullable()->constrained('stations');
            $table->text('remarks')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['session_id', 'created_at'], 'idx_logs_session');
            $table->index(['staff_user_id', 'created_at'], 'idx_logs_staff');
            $table->index(['action_type', 'created_at'], 'idx_logs_action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_logs');
    }
};
