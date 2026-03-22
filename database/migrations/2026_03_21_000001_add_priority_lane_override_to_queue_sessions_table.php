<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queue_sessions', function (Blueprint $table) {
            $table->boolean('priority_lane_override')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('queue_sessions', function (Blueprint $table) {
            $table->dropColumn('priority_lane_override');
        });
    }
};
