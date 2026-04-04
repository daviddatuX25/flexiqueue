<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edge_device_state', function (Blueprint $table) {
            $table->string('scheduled_sync_time', 5)->default('17:00')->after('last_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('edge_device_state', function (Blueprint $table) {
            $table->dropColumn('scheduled_sync_time');
        });
    }
};
