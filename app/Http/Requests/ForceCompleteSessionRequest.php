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
            'auth_type' => ['nullable', 'string', 'in:preset_pin,preset_qr,pin,qr'],
            'supervisor_user_id' => ['nullable', 'required_if:auth_type,preset_pin', 'integer', 'exists:users,id'],
            'supervisor_pin' => ['nullable', 'required_if:auth_type,preset_pin', 'string', 'size:6'],
            'temp_code' => ['nullable', 'required_if:auth_type,pin', 'string', 'size:6', 'regex:/^\d{6}$/'],
            'qr_scan_token' => ['nullable', 'required_if:auth_type,qr', 'required_if:auth_type,preset_qr', 'string', 'min:1', 'max:128'],
        ];
    }
}
