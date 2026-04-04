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
            }

            if (! array_key_exists('segment1_no_pre_tail_fallback', $langs['en'])) {
                $langs['en']['segment1_no_pre_tail_fallback'] = 'Calling {token}, please proceed to your station';
            }

            if (! array_key_exists('segment1_no_pre_tail_fallback', $langs['fil'])) {
                $langs['fil']['segment1_no_pre_tail_fallback'] = '';
            }

            if (! array_key_exists('segment1_no_pre_tail_fallback', $langs['ilo'])) {
                $langs['ilo']['segment1_no_pre_tail_fallback'] = '';
            }

            DB::table('token_tts_settings')->where('id', $row->id)->update([
                'default_languages' => json_encode($langs, JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    public function down(): void
    {
        // Non-destructive rollback: only remove the added key when present.
        foreach (DB::table('token_tts_settings')->get() as $row) {
            $decoded = $row->default_languages !== null ? json_decode((string) $row->default_languages, true) : null;
            $langs = is_array($decoded) ? $decoded : [];

            foreach (['en', 'fil', 'ilo'] as $code) {
                if (isset($langs[$code]) && is_array($langs[$code]) && array_key_exists('segment1_no_pre_tail_fallback', $langs[$code])) {
                    unset($langs[$code]['segment1_no_pre_tail_fallback']);
                }
            }

            DB::table('token_tts_settings')->where('id', $row->id)->update([
                'default_languages' => json_encode($langs, JSON_UNESCAPED_UNICODE),
            ]);
        }
    }
};
