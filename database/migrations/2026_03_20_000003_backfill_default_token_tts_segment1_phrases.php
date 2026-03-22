<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('token_tts_settings')->get() as $row) {
            $decoded = null;
            if (isset($row->default_languages) && $row->default_languages !== null) {
                $decoded = json_decode((string) $row->default_languages, true);
            }

            $langs = is_array($decoded) ? $decoded : [];

            foreach (['en', 'fil', 'ilo'] as $code) {
                if (! isset($langs[$code]) || ! is_array($langs[$code])) {
                    $langs[$code] = [];
                }

                $pre = $langs[$code]['pre_phrase'] ?? null;
                if (! is_string($pre) || trim($pre) === '') {
                    $langs[$code]['pre_phrase'] = 'Calling';
                }

                $tail = $langs[$code]['token_bridge_tail'] ?? null;
                if (! is_string($tail) || trim($tail) === '') {
                    $langs[$code]['token_bridge_tail'] = 'please proceed to your station';
                }

                $fallback = $langs[$code]['segment1_no_pre_tail_fallback'] ?? null;
                if (! is_string($fallback) || trim($fallback) === '') {
                    $langs[$code]['segment1_no_pre_tail_fallback'] = 'Calling {token}, please proceed to your station';
                }
            }

            DB::table('token_tts_settings')->where('id', $row->id)->update([
                'default_languages' => json_encode($langs, JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    public function down(): void
    {
        // Non-destructive rollback: only remove keys when they exactly equal the backfilled defaults.
        foreach (DB::table('token_tts_settings')->get() as $row) {
            $decoded = null;
            if (isset($row->default_languages) && $row->default_languages !== null) {
                $decoded = json_decode((string) $row->default_languages, true);
            }

            $langs = is_array($decoded) ? $decoded : [];

            foreach (['en', 'fil', 'ilo'] as $code) {
                if (! isset($langs[$code]) || ! is_array($langs[$code])) {
                    continue;
                }

                if (($langs[$code]['pre_phrase'] ?? null) === 'Calling') {
                    unset($langs[$code]['pre_phrase']);
                }

                if (($langs[$code]['token_bridge_tail'] ?? null) === 'please proceed to your station') {
                    unset($langs[$code]['token_bridge_tail']);
                }

                if (($langs[$code]['segment1_no_pre_tail_fallback'] ?? null) === 'Calling {token}, please proceed to your station') {
                    unset($langs[$code]['segment1_no_pre_tail_fallback']);
                }
            }

            DB::table('token_tts_settings')->where('id', $row->id)->update([
                'default_languages' => json_encode($langs, JSON_UNESCAPED_UNICODE),
            ]);
        }
    }
};
