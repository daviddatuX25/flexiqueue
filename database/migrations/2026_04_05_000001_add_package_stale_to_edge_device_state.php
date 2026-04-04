<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edge_device_state', function (Blueprint $table) {
            $table->boolean('package_stale')->default(false)->after('package_version');
        });
    }

    public function down(): void
    {
        Schema::table('edge_device_state', function (Blueprint $table) {
            $table->dropColumn('package_stale');
        });
    }
};
