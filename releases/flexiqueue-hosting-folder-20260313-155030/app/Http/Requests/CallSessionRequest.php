<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Per 08-API-SPEC-PHASE1 §3.2: POST /api/sessions/{id}/call.
 * Optional auth when call would override priority (require_permission_before_override + FIFO with regular before PWD).
 */
class CallSessionRequest extends FormRequest
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
            'auth_type' => ['nullable', 'string', 'in:preset_pin,preset_qr,temp_pin,temp_qr,pin,qr'],
            'supervisor_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'supervisor_pin' => ['nullable', 'string', 'size:6'],
            'temp_code' => ['nullable', 'string', 'size:6', 'regex:/^\d{6}$/'],
            'qr_scan_token' => ['nullable', 'string', 'min:1', 'max:128'],
        ];
    }
}
