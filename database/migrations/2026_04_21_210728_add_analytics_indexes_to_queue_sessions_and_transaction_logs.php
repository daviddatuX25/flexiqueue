<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queue_sessions', function (Blueprint $table) {
            $table->index('started_at', 'idx_queue_sessions_started_at');
            $table->index('completed_at', 'idx_queue_sessions_completed_at');
            $table->index('track_id', 'idx_queue_sessions_track');
            $table->index(['status', 'completed_at', 'started_at'], 'idx_queue_sessions_completed_range');
        });

        Schema::table('transaction_logs', function (Blueprint $table) {
            $table->index('station_id', 'idx_transaction_logs_station');
        });

        Schema::table('tokens', function (Blueprint $table) {
            $table->index('site_id', 'idx_tokens_site');
            $table->index('status', 'idx_tokens_status');
        });
    }

    public function down(): void
    {
        Schema::table('queue_sessions', function (Blueprint $table) {
            $table->dropIndex('idx_queue_sessions_started_at');
            $table->dropIndex('idx_queue_sessions_completed_at');
            $table->dropIndex('idx_queue_sessions_track');
            $table->dropIndex('idx_queue_sessions_completed_range');
        });

        Schema::table('transaction_logs', function (Blueprint $table) {
            $table->dropIndex('idx_transaction_logs_station');
        });

        Schema::table('tokens', function (Blueprint $table) {
            $table->dropIndex('idx_tokens_site');
            $table->dropIndex('idx_tokens_status');
        });
    }
};