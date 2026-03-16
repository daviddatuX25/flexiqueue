<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per flexiqueue-a3wh: add 'enqueue_back' transaction log action.
     *
     * MariaDB/MySQL use ENUM; SQLite already uses VARCHAR(255) so no schema change is required there.
     */
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement("ALTER TABLE transaction_logs MODIFY COLUMN action_type ENUM(
            'bind',
            'call',
            'check_in',
            'transfer',
            'override',
            'complete',
            'cancel',
            'no_show',
            'reorder',
            'force_complete',
            'identity_mismatch',
            'hold',
            'resume_from_hold',
            'enqueue_back'
        ) NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement("ALTER TABLE transaction_logs MODIFY COLUMN action_type ENUM(
            'bind',
            'call',
            'check_in',
            'transfer',
            'override',
            'complete',
            'cancel',
            'no_show',
            'reorder',
            'force_complete',
            'identity_mismatch',
            'hold',
            'resume_from_hold'
        ) NOT NULL");
    }
};
