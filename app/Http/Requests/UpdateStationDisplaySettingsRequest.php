<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Per plan: PUT /api/stations/{station}/display-settings — display_audio_muted, display_audio_volume, display_page_zoom.
 */
class UpdateStationDisplaySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'display_audio_muted' => ['sometimes', 'boolean'],
            'display_audio_volume' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'display_page_zoom' => ['sometimes', 'numeric', Rule::in([0.75, 0.85, 1, 1.1, 1.25])],
        ];
    }
}
