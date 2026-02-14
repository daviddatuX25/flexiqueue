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
            'supervisor_user_id' => ['required', 'integer', 'exists:users,id'],
            'supervisor_pin' => ['required', 'string', 'size:6'],
        ];
    }
}
