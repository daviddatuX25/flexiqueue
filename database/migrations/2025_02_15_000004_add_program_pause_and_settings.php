<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per plan: is_paused for queue-time exclusion; settings JSON for no_show_timer_seconds etc.
     */
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->boolean('is_paused')->default(false)->after('is_active');
            $table->json('settings')->nullable()->after('is_paused');
        });
    }

    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->dropColumn(['is_paused', 'settings']);
        });
    }
};
