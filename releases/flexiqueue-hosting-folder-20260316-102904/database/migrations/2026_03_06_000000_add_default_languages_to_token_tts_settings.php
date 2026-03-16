<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Global default per-language TTS: en, fil, ilo with voice_id, rate, pre_phrase.
     */
    public function up(): void
    {
        Schema::table('token_tts_settings', function (Blueprint $table) {
            $table->json('default_languages')->nullable()->after('rate');
        });
    }

    public function down(): void
    {
        Schema::table('token_tts_settings', function (Blueprint $table) {
            $table->dropColumn('default_languages');
        });
    }
};
