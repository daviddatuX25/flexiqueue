<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per PROCESS-STATION-REFACTOR Phase 1: Add process_id to track_steps, backfill from station_id.
 * Each station gets one process (same name); station_process populated. station_id kept for now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('track_steps', function (Blueprint $table) {
            $table->foreignId('process_id')->nullable()->after('station_id')
                ->constrained('processes')->cascadeOnDelete();
        });

        // Backfill: for each station, create process with station name, link in station_process, set track_steps.process_id
        $stations = DB::table('stations')->get(['id', 'program_id', 'name']);
        foreach ($stations as $station) {
            $process = DB::table('processes')->where('program_id', $station->program_id)
                ->where('name', $station->name)->first();
            if (! $process) {
                $processId = DB::table('processes')->insertGetId([
                    'program_id' => $station->program_id,
                    'name' => $station->name,
                    'description' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $processId = $process->id;
            }

            if (! DB::table('station_process')->where('station_id', $station->id)->where('process_id', $processId)->exists()) {
                DB::table('station_process')->insert([
                    'station_id' => $station->id,
                    'process_id' => $processId,
                ]);
            }

            DB::table('track_steps')->where('station_id', $station->id)->update([
                'process_id' => $processId,
                'updated_at' => now(),
            ]);
        }

        // Note: process_id left nullable for SQLite test compatibility. Production uses MariaDB;
        // Phase 3 migration will enforce NOT NULL when dropping station_id.
    }

    public function down(): void
    {
        Schema::table('track_steps', function (Blueprint $table) {
            $table->dropForeign(['process_id']);
        });
    }
};
