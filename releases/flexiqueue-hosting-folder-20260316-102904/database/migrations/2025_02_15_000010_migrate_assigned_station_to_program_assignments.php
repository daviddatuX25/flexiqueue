<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Migrate existing users.assigned_station_id into program_station_assignments.
     */
    public function up(): void
    {
        $rows = DB::table('users')
            ->whereNotNull('assigned_station_id')
            ->join('stations', 'users.assigned_station_id', '=', 'stations.id')
            ->select('users.id as user_id', 'stations.id as station_id', 'stations.program_id')
            ->get();

        foreach ($rows as $row) {
            DB::table('program_station_assignments')->insertOrIgnore([
                'program_id' => $row->program_id,
                'user_id' => $row->user_id,
                'station_id' => $row->station_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('program_station_assignments')->truncate();
    }
};
