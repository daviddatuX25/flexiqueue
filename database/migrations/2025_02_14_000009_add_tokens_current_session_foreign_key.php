<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds FK from tokens.current_session_id → sessions.id (circular dependency resolved).
     */
    public function up(): void
    {
        Schema::table('tokens', function (Blueprint $table) {
            $table->foreign('current_session_id')
                ->references('id')
                ->on('queue_sessions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tokens', function (Blueprint $table) {
            $table->dropForeign(['current_session_id']);
        });
    }
};
