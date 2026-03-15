<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Per REFACTORING-ISSUE-LIST Issue 19: add SQLite triggers to enforce append-only
     * behavior on transaction_logs even when bypassing the Eloquent model.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement("
            CREATE TRIGGER IF NOT EXISTS tr_transaction_logs_prevent_update
            BEFORE UPDATE ON transaction_logs
            BEGIN
                SELECT RAISE(ABORT, 'transaction_logs is append-only; updates are not permitted');
            END;
        ");

        DB::statement("
            CREATE TRIGGER IF NOT EXISTS tr_transaction_logs_prevent_delete
            BEFORE DELETE ON transaction_logs
            BEGIN
                SELECT RAISE(ABORT, 'transaction_logs is append-only; deletes are not permitted');
            END;
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS tr_transaction_logs_prevent_update');
        DB::statement('DROP TRIGGER IF EXISTS tr_transaction_logs_prevent_delete');
    }
};

