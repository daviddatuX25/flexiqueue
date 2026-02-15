<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per docs/plans/TRACK-OVERRIDES-REFACTOR.md §1.2: one-off path for custom overrides.
     * JSON: [station_id1, station_id2, ...] ordered path. When set, FlowEngine uses this instead of track_steps.
     */
    public function up(): void
    {
        Schema::table('queue_sessions', function (Blueprint $table) {
            $table->json('override_steps')->nullable()->after('current_step_order');
        });
    }

    public function down(): void
    {
        Schema::table('queue_sessions', function (Blueprint $table) {
            $table->dropColumn('override_steps');
        });
    }
};
