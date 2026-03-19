<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per plan: add 'call' and 'reorder' action types for audit trail.
     * ENUM MODIFY is MySQL only; on SQLite we recreate table so action_type accepts new values.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE transaction_logs MODIFY COLUMN action_type ENUM(
                'bind', 'call', 'check_in', 'transfer', 'override',
                'complete', 'cancel', 'no_show', 'reorder', 'force_complete', 'identity_mismatch'
            ) NOT NULL");
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            // SQLite: enum becomes CHECK; recreate with VARCHAR(255) so 'call' and 'reorder' are allowed.
            DB::statement('CREATE TABLE transaction_logs_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                session_id INTEGER NOT NULL,
                station_id INTEGER NULL,
                staff_user_id INTEGER NOT NULL,
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
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE transaction_logs MODIFY COLUMN action_type ENUM(
                'bind', 'check_in', 'transfer', 'override',
                'complete', 'cancel', 'no_show', 'force_complete', 'identity_mismatch'
            ) NOT NULL");
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            // Restore original CHECK: recreate with action_type allowing only original enum values.
            DB::statement('CREATE TABLE transaction_logs_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                session_id INTEGER NOT NULL,
                station_id INTEGER NULL,
                staff_user_id INTEGER NOT NULL,
                action_type VARCHAR(255) NOT NULL CHECK(action_type IN (\'bind\', \'check_in\', \'transfer\', \'override\', \'complete\', \'cancel\', \'no_show\', \'force_complete\', \'identity_mismatch\')),
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
            DB::statement('INSERT INTO transaction_logs_new SELECT id, session_id, station_id, staff_user_id, action_type, previous_station_id, next_station_id, remarks, metadata, created_at FROM transaction_logs WHERE action_type NOT IN (\'call\', \'reorder\')');
            Schema::drop('transaction_logs');
            DB::statement('ALTER TABLE transaction_logs_new RENAME TO transaction_logs');
            DB::statement('CREATE INDEX idx_logs_session ON transaction_logs(session_id, created_at)');
            DB::statement('CREATE INDEX idx_logs_staff ON transaction_logs(staff_user_id, created_at)');
            DB::statement('CREATE INDEX idx_logs_action ON transaction_logs(action_type, created_at)');
        }
    }
};
