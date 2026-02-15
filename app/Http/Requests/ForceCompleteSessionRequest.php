<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Per 08-API-SPEC-PHASE1 §3.8: POST /api/sessions/{id}/force-complete.
 */
class ForceCompleteSessionRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:1'],
            'auth_type' => ['nullable', 'string', 'in:preset_pin,temp_pin,temp_qr'],
            'supervisor_user_id' => ['required_if:auth_type,preset_pin', 'required_unless:auth_type,temp_pin,temp_qr', 'integer', 'exists:users,id'],
            'supervisor_pin' => ['required_if:auth_type,preset_pin', 'required_unless:auth_type,temp_pin,temp_qr', 'string', 'size:6'],
            'temp_code' => ['required_if:auth_type,temp_pin', 'string', 'size:6', 'regex:/^\d{6}$/'],
            'qr_scan_token' => ['required_if:auth_type,temp_qr', 'string', 'min:1', 'max:128'],
        ];
    }
}
