<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edge_device_state', function (Blueprint $table): void {
            $table->boolean('update_available')->default(false)->after('package_stale');
        });
    }

    public function down(): void
    {
        Schema::table('edge_device_state', function (Blueprint $table): void {
            $table->dropColumn('update_available');
        });
    }
};
