<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per plan: public self-serve bind records null staff_user_id (audit shows "Public").
     * MODIFY is MySQL/MariaDB only; SQLite requires table recreate to make column nullable.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            Schema::table('transaction_logs', function (Blueprint $table) {
                $table->dropForeign(['staff_user_id']);
            });
            DB::statement('ALTER TABLE transaction_logs MODIFY staff_user_id BIGINT UNSIGNED NULL');
            Schema::table('transaction_logs', function (Blueprint $table) {
                $table->foreign('staff_user_id')->references('id')->on('users')->nullOnDelete();
            });
            return;
        }

        if ($driver === 'sqlite') {
            // SQLite cannot ALTER COLUMN; recreate table with nullable staff_user_id.
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
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            Schema::table('transaction_logs', function (Blueprint $table) {
                $table->dropForeign(['staff_user_id']);
            });
            DB::statement('ALTER TABLE transaction_logs MODIFY staff_user_id BIGINT UNSIGNED NOT NULL');
            Schema::table('transaction_logs', function (Blueprint $table) {
                $table->foreign('staff_user_id')->references('id')->on('users');
            });
            return;
        }

        if ($driver === 'sqlite') {
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
};
