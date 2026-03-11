<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Per TRACK-OVERRIDES-REFACTOR §2.3: POST /api/sessions/{id}/override.
 * Track-based: target_track_id required; custom_steps optional for one-off path.
 * Per flexiqueue-eiju: reason required only for custom path, not predefined track.
 */
class OverrideSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'target_track_id' => ['required', 'integer', 'exists:service_tracks,id'],
            'custom_steps' => ['nullable', 'array'],
            'custom_steps.*' => ['integer', 'exists:stations,id'],
            'reason' => [
                Rule::when(
                    is_array($this->custom_steps ?? null) && count($this->custom_steps ?? []) > 0,
                    ['required', 'string', 'min:1'],
                    ['nullable', 'string']
                ),
            ],
            'auth_type' => ['nullable', 'string', 'in:preset_pin,preset_qr,temp_pin,temp_qr,pin,qr'],
            'supervisor_user_id' => ['nullable', 'required_if:auth_type,preset_pin', 'integer', 'exists:users,id'],
            'supervisor_pin' => ['nullable', 'required_if:auth_type,preset_pin', 'string', 'size:6'],
            'temp_code' => ['nullable', 'required_if:auth_type,temp_pin', 'required_if:auth_type,pin', 'string', 'size:6', 'regex:/^\d{6}$/'],
            'qr_scan_token' => ['nullable', 'required_if:auth_type,temp_qr', 'required_if:auth_type,qr', 'required_if:auth_type,preset_qr', 'string', 'min:1', 'max:128'],
        ];
    }
}
