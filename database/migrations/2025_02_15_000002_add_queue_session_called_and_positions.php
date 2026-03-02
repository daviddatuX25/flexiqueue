<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per plan: add 'called' status, station_queue_position for reordering, queued_at_station for pause-aware wait time.
     * ENUM MODIFY is MySQL/MariaDB only; SQLite uses string columns so no schema change needed for new values.
     */
    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE queue_sessions MODIFY COLUMN status ENUM('waiting', 'called', 'serving', 'completed', 'cancelled', 'no_show') DEFAULT 'waiting'");
        }

        Schema::table('queue_sessions', function (Blueprint $table) {
            $table->unsignedInteger('station_queue_position')->nullable()->after('current_step_order');
            $table->timestamp('queued_at_station')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('queue_sessions', function (Blueprint $table) {
            $table->dropColumn(['station_queue_position', 'queued_at_station']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE queue_sessions MODIFY COLUMN status ENUM('waiting', 'serving', 'completed', 'cancelled', 'no_show') DEFAULT 'waiting'");
        }
    }
};
