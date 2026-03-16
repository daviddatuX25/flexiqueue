<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ensure tokens.pronounce_as exists (idempotent).
     * Fixes orphaned state where add_pronounce_as migration was recorded but column was never added.
     */
    public function up(): void
    {
        if (! Schema::hasTable('tokens') || Schema::hasColumn('tokens', 'pronounce_as')) {
            return;
        }
        Schema::table('tokens', function (Blueprint $table) {
            $table->string('pronounce_as', 10)->nullable()->default('letters')->after('physical_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('tokens') && Schema::hasColumn('tokens', 'pronounce_as')) {
            Schema::table('tokens', function (Blueprint $table) {
                $table->dropColumn('pronounce_as');
            });
        }
    }
};
