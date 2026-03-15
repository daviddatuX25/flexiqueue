<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Per PROCESS-STATION-REFACTOR Phase 3: Make station_id nullable before dropping.
 * Steps now use process_id; station_id kept temporarily for migration compatibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE track_steps MODIFY station_id BIGINT UNSIGNED NULL');
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE track_steps MODIFY station_id BIGINT UNSIGNED NOT NULL');
        }
    }
};
