<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per TTS plan: track token TTS generation state and whether it participates in pre-generation.
     */
    public function up(): void
    {
        Schema::table('tokens', function (Blueprint $table) {
            $table->string('tts_status', 32)
                ->nullable()
                ->after('tts_audio_path');
            $table->boolean('tts_pre_generate_enabled')
                ->default(false)
                ->after('tts_status');
        });

        // Backfill: tokens that already have audio are considered pre-generated.
        Schema::table('tokens', function (Blueprint $table) {
            // no schema changes; this closure exists so we can run a DB update in a separate step if needed
        });

        // Use DB facade without importing to avoid issues if tokens table is empty in some environments.
        if (Schema::hasColumn('tokens', 'tts_audio_path')) {
            \Illuminate\Support\Facades\DB::table('tokens')
                ->whereNotNull('tts_audio_path')
                ->update([
                    'tts_status' => 'pre_generated',
                    'tts_pre_generate_enabled' => true,
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('tokens', function (Blueprint $table) {
            if (Schema::hasColumn('tokens', 'tts_pre_generate_enabled')) {
                $table->dropColumn('tts_pre_generate_enabled');
            }
            if (Schema::hasColumn('tokens', 'tts_status')) {
                $table->dropColumn('tts_status');
            }
        });
    }
};

