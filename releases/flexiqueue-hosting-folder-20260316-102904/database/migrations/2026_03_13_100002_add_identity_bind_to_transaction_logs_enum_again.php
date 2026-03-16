<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add 'identity_bind' to action_type ENUM (was dropped when enqueue_back was added in 000220).
     * MariaDB/MySQL only; SQLite uses VARCHAR(255).
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
            'enqueue_back',
            'identity_bind'
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
            'resume_from_hold',
            'enqueue_back'
        ) NOT NULL");
    }
};
