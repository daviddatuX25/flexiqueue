<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per docs/plans/TRACK-OVERRIDES-REFACTOR.md §1.1: add awaiting_approval session status.
     * ENUM MODIFY is MySQL/MariaDB only; SQLite uses string columns so no schema change needed.
     */
    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE queue_sessions MODIFY COLUMN status ENUM('waiting', 'called', 'serving', 'awaiting_approval', 'completed', 'cancelled', 'no_show') DEFAULT 'waiting'");
        }
    }

    public function down(): void
    {
        DB::table('queue_sessions')->where('status', 'awaiting_approval')->update(['status' => 'waiting']);

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE queue_sessions MODIFY COLUMN status ENUM('waiting', 'called', 'serving', 'completed', 'cancelled', 'no_show') DEFAULT 'waiting'");
        }
    }
};
