<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per plan: per-station display audio (mute/volume) for station display page.
     */
    public function up(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->json('settings')->nullable()->after('priority_first_override');
        });
    }

    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->dropColumn('settings');
        });
    }
};
