<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per plan: On SQLite, transaction_logs.action_type may still have a CHECK that excludes
     * 'call' and 'reorder' (2025_02_15 only updated MariaDB). Recreate the table with
     * action_type VARCHAR(255) and nullable staff_user_id so Call Next (and reorder) work.
     * Idempotent: matches 2026_02_28 SQLite schema so safe whether or not 2026 has run.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement('CREATE TABLE transaction_logs_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            session_id INTEGER NOT NULL,
            station_id INTEGER NULL,
            staff_user_id INTEGER NULL,
            action_type VARCHAR(255) NOT NULL,
            previous_station_id INTEGER NULL,
            next_station_id INTEGER NULL,
            remarks TEXT NULL,
            metadata TEXT NULL,
            created_at DATETIME NOT NULL,
            FOREIGN KEY (session_id) REFERENCES queue_sessions(id),
            FOREIGN KEY (station_id) REFERENCES stations(id),
            FOREIGN KEY (staff_user_id) REFERENCES users(id),
            FOREIGN KEY (previous_station_id) REFERENCES stations(id),
            FOREIGN KEY (next_station_id) REFERENCES stations(id)
        )');
        DB::statement('INSERT INTO transaction_logs_new SELECT id, session_id, station_id, staff_user_id, action_type, previous_station_id, next_station_id, remarks, metadata, created_at FROM transaction_logs');
        Schema::drop('transaction_logs');
        DB::statement('ALTER TABLE transaction_logs_new RENAME TO transaction_logs');
        DB::statement('CREATE INDEX idx_logs_session ON transaction_logs(session_id, created_at)');
        DB::statement('CREATE INDEX idx_logs_staff ON transaction_logs(staff_user_id, created_at)');
        DB::statement('CREATE INDEX idx_logs_action ON transaction_logs(action_type, created_at)');
    }

    /**
     * Reverse: not implemented for SQLite (schema revert would require restoring enum CHECK).
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }
        // Irreversible for SQLite without storing previous constraint; no-op.
    }
};
