<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Per 08-API-SPEC-PHASE1 §3.3: POST /api/sessions/{id}/override.
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
            'target_station_id' => ['required', 'integer', 'exists:stations,id'],
            'reason' => ['required', 'string', 'min:1'],
            'supervisor_user_id' => ['required', 'integer', 'exists:users,id'],
            'supervisor_pin' => ['required', 'string', 'size:6'],
        ];
    }
}
