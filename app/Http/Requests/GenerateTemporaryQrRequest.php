<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Per docs/plans/PIN-QR-AUTHORIZATION-SYSTEM.md §4: POST /api/auth/temporary-qr.
 */
class GenerateTemporaryQrRequest extends FormRequest
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
            'expiry_mode' => ['required', 'string', Rule::in(['time_only', 'usage_only', 'time_or_usage'])],
            'expires_in_seconds' => [
                'nullable',
                'integer',
                'min:0',
                'max:315360000', // 0 = no expiry (10yr sentinel)
                Rule::requiredIf(fn () => in_array($this->input('expiry_mode'), ['time_only', 'time_or_usage'], true)),
                Rule::prohibitedIf(fn () => $this->input('expiry_mode') === 'usage_only'),
            ],
            'max_uses' => [
                'nullable',
                'integer',
                'min:1',
                'max:9999',
                Rule::requiredIf(fn () => in_array($this->input('expiry_mode'), ['usage_only', 'time_or_usage'], true)),
                Rule::prohibitedIf(fn () => $this->input('expiry_mode') === 'time_only'),
            ],
        ];
    }
}
