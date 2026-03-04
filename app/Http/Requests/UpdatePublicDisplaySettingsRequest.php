<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Per plan: POST /api/public/display-settings — PIN required; optional display/triage settings.
 * Public (no auth); used by display board and public triage settings modal.
 */
class UpdatePublicDisplaySettingsRequest extends FormRequest
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
            'pin' => ['required', 'string', 'size:6', 'regex:/^\d{6}$/'],
            'display_audio_muted' => ['sometimes', 'boolean'],
            'display_audio_volume' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'tts_source' => ['sometimes', 'string', 'in:browser,server'],
            'display_tts_voice' => ['sometimes', 'nullable', 'string', 'max:500'],
            'enable_display_hid_barcode' => ['sometimes', 'boolean'],
            'enable_public_triage_hid_barcode' => ['sometimes', 'boolean'],
        ];
    }
}
