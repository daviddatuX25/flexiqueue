<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per docs/plans/TRACK-OVERRIDES-REFACTOR.md §1.1: add awaiting_approval session status.
     * Sessions with pending permission requests use this status; current_station_id = null (detached from queues).
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE queue_sessions MODIFY COLUMN status ENUM('waiting', 'called', 'serving', 'awaiting_approval', 'completed', 'cancelled', 'no_show') DEFAULT 'waiting'");
    }

    public function down(): void
    {
        // Ensure no sessions are in awaiting_approval before rolling back
        DB::table('queue_sessions')->where('status', 'awaiting_approval')->update(['status' => 'waiting']);

        DB::statement("ALTER TABLE queue_sessions MODIFY COLUMN status ENUM('waiting', 'called', 'serving', 'completed', 'cancelled', 'no_show') DEFAULT 'waiting'");
    }
};
