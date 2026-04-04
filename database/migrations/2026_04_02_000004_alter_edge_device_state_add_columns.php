<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite does not support ALTER COLUMN or DROP CONSTRAINT.
            // Recreate the table with the updated enum and new columns.
            DB::statement('PRAGMA foreign_keys = OFF');

            DB::statement("
                CREATE TABLE edge_device_state_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    paired_at DATETIME NULL,
                    central_url VARCHAR(500) NULL,
                    site_id INTEGER UNSIGNED NULL,
                    site_name VARCHAR(255) NULL,
                    device_token TEXT NULL,
                    sync_mode VARCHAR(255) CHECK(sync_mode IN ('auto','end_of_event')) NOT NULL DEFAULT 'auto',
                    supervisor_admin_access TINYINT(1) NOT NULL DEFAULT 0,
                    active_program_id INTEGER UNSIGNED NULL,
                    active_program_name VARCHAR(255) NULL,
                    session_active TINYINT(1) NOT NULL DEFAULT 0,
                    last_synced_at DATETIME NULL,
                    id_offset INTEGER UNSIGNED NULL,
                    app_version VARCHAR(50) NULL,
                    package_version VARCHAR(255) NULL,
                    created_at DATETIME NULL,
                    updated_at DATETIME NULL
                )
            ");

            // Migrate existing rows (sync_mode values mapped: bridge→auto, sync→auto)
            DB::statement("
                INSERT INTO edge_device_state_new
                    (id, paired_at, central_url, site_id, site_name, device_token,
                     sync_mode, supervisor_admin_access, active_program_id, active_program_name,
                     session_active, last_synced_at, id_offset, app_version, package_version,
                     created_at, updated_at)
                SELECT id, paired_at, central_url, site_id, site_name, device_token,
                       'auto',
                       supervisor_admin_access, active_program_id, active_program_name,
                       session_active, last_synced_at, NULL, NULL, NULL,
                       created_at, updated_at
                FROM edge_device_state
            ");

            DB::statement('DROP TABLE edge_device_state');
            DB::statement('ALTER TABLE edge_device_state_new RENAME TO edge_device_state');

            DB::statement('PRAGMA foreign_keys = ON');
        } else {
            // MariaDB/MySQL: add new columns then modify enum
            Schema::table('edge_device_state', function (Blueprint $table) {
                $table->unsignedBigInteger('id_offset')->nullable()->after('session_active');
                $table->string('app_version', 50)->nullable()->after('id_offset');
                $table->string('package_version', 255)->nullable()->after('app_version');
            });

            DB::statement("ALTER TABLE edge_device_state MODIFY COLUMN sync_mode ENUM('auto','end_of_event') NOT NULL DEFAULT 'auto'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF');

            DB::statement("
                CREATE TABLE edge_device_state_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    paired_at DATETIME NULL,
                    central_url VARCHAR(500) NULL,
                    site_id INTEGER UNSIGNED NULL,
                    site_name VARCHAR(255) NULL,
                    device_token TEXT NULL,
                    sync_mode VARCHAR(255) CHECK(sync_mode IN ('bridge','sync')) NOT NULL DEFAULT 'sync',
                    supervisor_admin_access TINYINT(1) NOT NULL DEFAULT 0,
                    active_program_id INTEGER UNSIGNED NULL,
                    active_program_name VARCHAR(255) NULL,
                    session_active TINYINT(1) NOT NULL DEFAULT 0,
                    last_synced_at DATETIME NULL,
                    created_at DATETIME NULL,
                    updated_at DATETIME NULL
                )
            ");

            DB::statement("
                INSERT INTO edge_device_state_new
                    (id, paired_at, central_url, site_id, site_name, device_token,
                     sync_mode, supervisor_admin_access, active_program_id, active_program_name,
                     session_active, last_synced_at, created_at, updated_at)
                SELECT id, paired_at, central_url, site_id, site_name, device_token,
                       'sync',
                       supervisor_admin_access, active_program_id, active_program_name,
                       session_active, last_synced_at, created_at, updated_at
                FROM edge_device_state
            ");

            DB::statement('DROP TABLE edge_device_state');
            DB::statement('ALTER TABLE edge_device_state_new RENAME TO edge_device_state');

            DB::statement('PRAGMA foreign_keys = ON');
        } else {
            Schema::table('edge_device_state', function (Blueprint $table) {
                $table->dropColumn(['id_offset', 'app_version', 'package_version']);
            });

            DB::statement("ALTER TABLE edge_device_state MODIFY COLUMN sync_mode ENUM('bridge','sync') NOT NULL DEFAULT 'sync'");
        }
    }
};
