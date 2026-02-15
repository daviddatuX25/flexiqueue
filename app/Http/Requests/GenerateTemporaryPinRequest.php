<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Per docs/plans/PIN-QR-AUTHORIZATION-SYSTEM.md §4: POST /api/auth/temporary-pin.
 */
class GenerateTemporaryPinRequest extends FormRequest
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
            'program_id' => ['nullable', 'integer', 'exists:programs,id'],
            'expires_in_seconds' => ['nullable', 'integer', 'min:0', 'max:315360000'], // 0 = no expiry (10yr sentinel)
        ];
    }
}
