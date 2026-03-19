<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per station holding-area plan: add 'hold' and 'resume_from_hold' transaction log actions.
     *
     * MySQL uses ENUM; SQLite already uses VARCHAR so no schema change is required there.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
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
            'identity_mismatch'
        ) NOT NULL");
    }
};

