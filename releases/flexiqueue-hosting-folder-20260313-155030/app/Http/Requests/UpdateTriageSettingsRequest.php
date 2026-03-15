<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/profile/triage-settings — Staff triage HID/camera preferences ("on this account").
 * Authenticated user only; updates own preferences.
 */
class UpdateTriageSettingsRequest extends FormRequest
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
            'allow_hid_barcode' => ['sometimes', 'boolean'],
            'allow_camera_scanner' => ['sometimes', 'boolean'],
        ];
    }
}
