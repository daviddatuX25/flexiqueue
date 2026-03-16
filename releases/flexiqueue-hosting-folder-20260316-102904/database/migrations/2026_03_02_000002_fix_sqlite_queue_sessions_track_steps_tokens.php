<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per plan: On SQLite, bring queue_sessions (status), track_steps (station_id nullable),
     * and tokens (status with deactivated) in line with MariaDB so Call Next, process-based
     * steps, and token deactivation work. Runs only on SQLite.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement('PRAGMA foreign_keys = OFF');

        $this->recreateTokens();
        $this->recreateTrackSteps();
        $this->recreateQueueSessions();

        DB::statement('PRAGMA foreign_keys = ON');
    }

    private function recreateTokens(): void
    {
        DB::statement('CREATE TABLE tokens_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            qr_code_hash VARCHAR(64) NOT NULL,
            physical_id VARCHAR(10) NOT NULL,
            status VARCHAR(255) NOT NULL DEFAULT \'available\',
            current_session_id INTEGER NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            deleted_at DATETIME NULL,
            FOREIGN KEY (current_session_id) REFERENCES queue_sessions(id)
        )');
        DB::statement('INSERT INTO tokens_new SELECT id, qr_code_hash, physical_id, status, current_session_id, created_at, updated_at, deleted_at FROM tokens');
        Schema::drop('tokens');
        DB::statement('ALTER TABLE tokens_new RENAME TO tokens');
        DB::statement('CREATE UNIQUE INDEX idx_tokens_hash ON tokens(qr_code_hash)');
        DB::statement('CREATE INDEX idx_tokens_physical ON tokens(physical_id)');
    }

    private function recreateTrackSteps(): void
    {
        DB::statement('CREATE TABLE track_steps_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            track_id INTEGER NOT NULL,
            station_id INTEGER NULL,
            process_id INTEGER NULL,
            step_order INTEGER NOT NULL,
            is_required INTEGER NOT NULL DEFAULT 1,
            estimated_minutes INTEGER NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            FOREIGN KEY (track_id) REFERENCES service_tracks(id) ON DELETE CASCADE,
            FOREIGN KEY (station_id) REFERENCES stations(id),
            FOREIGN KEY (process_id) REFERENCES processes(id) ON DELETE CASCADE
        )');
        DB::statement('INSERT INTO track_steps_new SELECT id, track_id, station_id, process_id, step_order, is_required, estimated_minutes, created_at, updated_at FROM track_steps');
        Schema::drop('track_steps');
        DB::statement('ALTER TABLE track_steps_new RENAME TO track_steps');
        DB::statement('CREATE UNIQUE INDEX idx_step_order ON track_steps(track_id, step_order)');
    }

    private function recreateQueueSessions(): void
    {
        DB::statement('CREATE TABLE queue_sessions_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            token_id INTEGER NOT NULL,
            program_id INTEGER NOT NULL,
            track_id INTEGER NOT NULL,
            alias VARCHAR(10) NOT NULL,
            client_category VARCHAR(50) NULL,
            current_station_id INTEGER NULL,
            current_step_order INTEGER NULL,
            override_steps TEXT NULL,
            station_queue_position INTEGER NULL,
            status VARCHAR(255) NOT NULL DEFAULT \'waiting\',
            started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            queued_at_station DATETIME NULL,
            completed_at DATETIME NULL,
            no_show_attempts INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            FOREIGN KEY (token_id) REFERENCES tokens(id),
            FOREIGN KEY (program_id) REFERENCES programs(id),
            FOREIGN KEY (track_id) REFERENCES service_tracks(id),
            FOREIGN KEY (current_station_id) REFERENCES stations(id)
        )');
        DB::statement('INSERT INTO queue_sessions_new SELECT id, token_id, program_id, track_id, alias, client_category, current_station_id, current_step_order, override_steps, station_queue_position, status, started_at, queued_at_station, completed_at, no_show_attempts, created_at, updated_at FROM queue_sessions');
        Schema::drop('queue_sessions');
        DB::statement('ALTER TABLE queue_sessions_new RENAME TO queue_sessions');
        DB::statement('CREATE INDEX idx_queue_sessions_active ON queue_sessions(status, current_station_id)');
        DB::statement('CREATE INDEX idx_queue_sessions_program ON queue_sessions(program_id, status)');
    }

    /**
     * Reverse: not implemented for SQLite (schema revert would require restoring enums/constraints).
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }
        // Irreversible for SQLite; no-op.
    }
};
