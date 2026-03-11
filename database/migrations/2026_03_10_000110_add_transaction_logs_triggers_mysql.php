<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Per REFACTORING-ISSUE-LIST Issue 19: add MySQL/MariaDB triggers to enforce append-only
     * behavior on transaction_logs even when bypassing the Eloquent model.
     */
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::unprepared("
            CREATE TRIGGER IF NOT EXISTS tr_transaction_logs_prevent_update
            BEFORE UPDATE ON transaction_logs
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'transaction_logs is append-only; updates are not permitted';
            END;
        ");

        DB::unprepared("
            CREATE TRIGGER IF NOT EXISTS tr_transaction_logs_prevent_delete
            BEFORE DELETE ON transaction_logs
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'transaction_logs is append-only; deletes are not permitted';
            END;
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS tr_transaction_logs_prevent_update');
        DB::unprepared('DROP TRIGGER IF EXISTS tr_transaction_logs_prevent_delete');
    }
};

