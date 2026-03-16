<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per XM2O identity binding plan: add 'identity_bind' transaction log action
     * for audit of session-level client identity binding.
     *
     * MariaDB/MySQL use ENUM; SQLite already uses VARCHAR so no schema change is required there.
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
            'resume_from_hold'
        ) NOT NULL");
    }
};

