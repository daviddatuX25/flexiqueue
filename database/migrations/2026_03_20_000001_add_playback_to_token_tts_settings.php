<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Product toggles + per-lang token bridge / closing (see docs/architecture/TTS.md).
     */
    public function up(): void
    {
        Schema::table('token_tts_settings', function (Blueprint $table) {
            $table->json('playback')->nullable()->after('default_languages');
        });

        $defaultPlayback = json_encode([
            'prefer_generated_audio' => true,
            'allow_custom_pronunciation' => true,
            'segment_2_enabled' => true,
        ]);

        foreach (DB::table('token_tts_settings')->get() as $row) {
            $langs = [];
            if (isset($row->default_languages) && $row->default_languages !== null) {
                $decoded = json_decode((string) $row->default_languages, true);
                $langs = is_array($decoded) ? $decoded : [];
            }
            foreach (['en', 'fil', 'ilo'] as $code) {
                if (! isset($langs[$code]) || ! is_array($langs[$code])) {
                    $langs[$code] = [];
                }
                if (! array_key_exists('token_bridge_tail', $langs[$code])) {
                    $langs[$code]['token_bridge_tail'] = '';
                }
                if (! array_key_exists('closing_without_segment2', $langs[$code])) {
                    $langs[$code]['closing_without_segment2'] = '';
                }
            }
            DB::table('token_tts_settings')->where('id', $row->id)->update([
                'playback' => $defaultPlayback,
                'default_languages' => json_encode($langs),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('token_tts_settings', function (Blueprint $table) {
            $table->dropColumn('playback');
        });
    }
};
