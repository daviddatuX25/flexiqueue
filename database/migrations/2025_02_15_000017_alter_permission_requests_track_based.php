<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per docs/plans/TRACK-OVERRIDES-REFACTOR.md §1.3: permission requests track-based.
     * Add target_track_id + custom_steps. target_station_id is dropped in a later migration when API switches.
     */
    public function up(): void
    {
        Schema::table('permission_requests', function (Blueprint $table) {
            $table->foreignId('target_track_id')->nullable()->after('action_type')
                ->constrained('service_tracks')->nullOnDelete();
            $table->json('custom_steps')->nullable()->after('target_track_id');
        });
    }

    public function down(): void
    {
        Schema::table('permission_requests', function (Blueprint $table) {
            $table->dropForeign(['target_track_id']);
            $table->dropColumn(['target_track_id', 'custom_steps']);
        });
    }
};
