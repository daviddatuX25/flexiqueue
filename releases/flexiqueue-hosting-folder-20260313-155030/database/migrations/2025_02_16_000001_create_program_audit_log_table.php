<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per flexiqueue-loo: audit trail for program session start/stop (activate/deactivate).
     * Separate from transaction_logs which is session-scoped; program lifecycle is program-level.
     */
    public function up(): void
    {
        Schema::create('program_audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->foreignId('staff_user_id')->constrained('users');
            $table->enum('action', ['session_start', 'session_stop']);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['program_id', 'created_at']);
            $table->index(['staff_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_audit_log');
    }
};
