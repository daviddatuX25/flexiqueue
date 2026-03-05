<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Per plan: PUT /api/stations/{station}/display-settings — display_audio_muted, display_audio_volume.
 */
class UpdateStationDisplaySettingsRequest extends FormRequest
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
            'display_audio_muted' => ['sometimes', 'boolean'],
            'display_audio_volume' => ['sometimes', 'numeric', 'min:0', 'max:1'],
        ];
    }
}
