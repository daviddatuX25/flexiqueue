<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per plan: add 'call' and 'reorder' action types for audit trail.
     * ENUM MODIFY is MySQL/MariaDB only; SQLite uses string columns so no schema change needed.
     */
    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE transaction_logs MODIFY COLUMN action_type ENUM(
                'bind', 'call', 'check_in', 'transfer', 'override',
                'complete', 'cancel', 'no_show', 'reorder', 'force_complete', 'identity_mismatch'
            ) NOT NULL");
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE transaction_logs MODIFY COLUMN action_type ENUM(
                'bind', 'check_in', 'transfer', 'override',
                'complete', 'cancel', 'no_show', 'force_complete', 'identity_mismatch'
            ) NOT NULL");
        }
    }
};
