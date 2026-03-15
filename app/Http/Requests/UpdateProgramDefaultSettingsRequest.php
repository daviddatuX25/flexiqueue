<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Per ISSUES-ELABORATION §2: update global default program settings.
 * Same rules as UpdateProgramRequest for the settings subset.
 */
class UpdateProgramDefaultSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'settings' => ['required', 'array'],
            'settings.no_show_timer_seconds' => ['sometimes', 'integer', 'min:5', 'max:600'],
            'settings.max_no_show_attempts' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'settings.require_permission_before_override' => ['sometimes', 'boolean'],
            'settings.priority_first' => ['sometimes', 'boolean'],
            'settings.balance_mode' => ['sometimes', 'string', 'in:fifo,alternate'],
            'settings.station_selection_mode' => ['sometimes', 'string', 'in:fixed,shortest_queue,least_busy,round_robin,least_recently_served'],
            'settings.alternate_ratio' => ['sometimes', 'array'],
            'settings.alternate_ratio.0' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'settings.alternate_ratio.1' => ['sometimes', 'integer', 'min:1', 'max:10'],
            // Per 08-API-SPEC-PHASE1 §5.1 / UpdateProgramRequest: keep global defaults aligned with per-program rules.
            'settings.alternate_priority_first' => ['sometimes', 'boolean'],
            'settings.display_scan_timeout_seconds' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:300'],
            'settings.display_audio_muted' => ['sometimes', 'boolean'],
            'settings.display_audio_volume' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'settings.display_tts_repeat_count' => ['sometimes', 'integer', 'min:1', 'max:3'],
            'settings.display_tts_repeat_delay_ms' => ['sometimes', 'integer', 'min:500', 'max:10000'],
            'settings.allow_public_triage' => ['sometimes', 'boolean'],
            'settings.allow_unverified_entry' => ['sometimes', 'boolean'],
            'settings.identity_binding_mode' => ['sometimes', 'string', 'in:disabled,required'],
            'settings.enable_display_hid_barcode' => ['sometimes', 'boolean'],
            'settings.enable_public_triage_hid_barcode' => ['sometimes', 'boolean'],
            'settings.enable_display_camera_scanner' => ['sometimes', 'boolean'],
            'settings.tts' => ['sometimes', 'array'],
            'settings.tts.active_language' => ['sometimes', 'string', 'in:en,fil,ilo'],
            'settings.tts.auto_generate_station_tts' => ['sometimes', 'boolean'],
            'settings.tts.connector' => ['sometimes', 'array'],
            'settings.tts.connector.languages' => ['sometimes', 'array'],
            'settings.tts.connector.languages.en' => ['sometimes', 'array'],
            'settings.tts.connector.languages.en.voice_id' => ['nullable', 'string', 'max:200'],
            'settings.tts.connector.languages.en.rate' => ['nullable', 'numeric', 'between:0.5,2.0'],
            'settings.tts.connector.languages.en.connector_phrase' => ['nullable', 'string', 'max:255'],
            'settings.tts.connector.languages.fil' => ['sometimes', 'array'],
            'settings.tts.connector.languages.fil.voice_id' => ['nullable', 'string', 'max:200'],
            'settings.tts.connector.languages.fil.rate' => ['nullable', 'numeric', 'between:0.5,2.0'],
            'settings.tts.connector.languages.fil.connector_phrase' => ['nullable', 'string', 'max:255'],
            'settings.tts.connector.languages.ilo' => ['sometimes', 'array'],
            'settings.tts.connector.languages.ilo.voice_id' => ['nullable', 'string', 'max:200'],
            'settings.tts.connector.languages.ilo.rate' => ['nullable', 'numeric', 'between:0.5,2.0'],
            'settings.tts.connector.languages.ilo.connector_phrase' => ['nullable', 'string', 'max:255'],
        ];
    }
}
