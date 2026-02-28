<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Plan: token-level "pronounce as letters vs word" for TTS (e.g. "A 3" vs "A3").
     */
    public function up(): void
    {
        Schema::table('tokens', function (Blueprint $table) {
            $table->string('pronounce_as', 10)->nullable()->default('letters')->after('physical_id');
        });
    }

    public function down(): void
    {
        Schema::table('tokens', function (Blueprint $table) {
            $table->dropColumn('pronounce_as');
        });
    }
};
