<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per PROCESS-STATION-REFACTOR: Station ↔ Process many-to-many.
 * Every station MUST have at least one process (enforced in app layer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('station_process', function (Blueprint $table) {
            $table->foreignId('station_id')->constrained('stations')->cascadeOnDelete();
            $table->foreignId('process_id')->constrained('processes')->cascadeOnDelete();

            $table->primary(['station_id', 'process_id'], 'station_process_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('station_process');
    }
};
