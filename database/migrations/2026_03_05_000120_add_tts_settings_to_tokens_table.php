<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per multi-language TTS plan: JSON-based per-language TTS settings on tokens.
     */
    public function up(): void
    {
        Schema::table('tokens', function (Blueprint $table) {
            if (! Schema::hasColumn('tokens', 'tts_settings')) {
                $table->json('tts_settings')->nullable()->after('tts_pre_generate_enabled');
            }
        });

        // Backfill: tokens that already have pre-generated audio should expose it under
        // the default language entry so new code can see a "ready" segment.
        if (
            Schema::hasColumn('tokens', 'tts_audio_path')
            && Schema::hasColumn('tokens', 'tts_status')
            && Schema::hasColumn('tokens', 'tts_settings')
        ) {
            \Illuminate\Support\Facades\DB::table('tokens')
                ->whereNotNull('tts_audio_path')
                ->where('tts_status', 'pre_generated')
                ->orderBy('id')
                ->chunkById(500, function ($tokens): void {
                    foreach ($tokens as $token) {
                        $settings = is_array($token->tts_settings) || is_object($token->tts_settings)
                            ? (array) $token->tts_settings
                            : [];

                        $languages = $settings['languages'] ?? [];
                        $en = $languages['en'] ?? [];

                        // Only set when there is no existing mapping for en.
                        if (! isset($en['audio_path'])) {
                            $en = array_merge(
                                [
                                    'voice_id' => null,
                                    'rate' => null,
                                    'pre_phrase' => null,
                                    'audio_path' => $token->tts_audio_path,
                                    'status' => 'ready',
                                ],
                                $en
                            );
                            $languages['en'] = $en;
                            $settings['languages'] = $languages;

                            \Illuminate\Support\Facades\DB::table('tokens')
                                ->where('id', $token->id)
                                ->update(['tts_settings' => json_encode($settings)]);
                        }
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::table('tokens', function (Blueprint $table) {
            if (Schema::hasColumn('tokens', 'tts_settings')) {
                $table->dropColumn('tts_settings');
            }
        });
    }
};

