<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Per 08-API-SPEC-PHASE1 §3.2: POST /api/sessions/{id}/transfer.
 */
class TransferSessionRequest extends FormRequest
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
            'mode' => ['required', 'in:standard,custom'],
            'target_station_id' => ['required_if:mode,custom', 'nullable', 'integer', 'exists:stations,id'],
        ];
    }
}
