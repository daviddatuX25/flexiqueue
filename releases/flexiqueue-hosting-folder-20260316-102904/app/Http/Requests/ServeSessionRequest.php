<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Per plan: POST /api/sessions/{session}/serve.
 * station_id optional for 'called'; required when session is 'waiting' (enforced in controller).
 */
class ServeSessionRequest extends FormRequest
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
            'station_id' => ['nullable', 'integer', 'exists:stations,id'],
        ];
    }
}
