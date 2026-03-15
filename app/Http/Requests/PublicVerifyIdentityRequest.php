<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Per IDENTITY-BINDING-FINAL-IMPLEMENTATION-PLAN §3.2: POST /api/public/verify-identity.
 * program_id, first_name, last_name, birth_date, mobile; token_id or qr_hash; track_id.
 */
class PublicVerifyIdentityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'program_id' => ['required', 'integer', 'exists:programs,id'],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'birth_date' => ['required', 'date', 'before_or_equal:today', 'after:'.now()->subYears(120)->format('Y-m-d')],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:100'],
            'mobile' => ['required', 'string', 'max:30'],
            'token_id' => ['nullable', 'integer', 'exists:tokens,id'],
            'qr_hash' => ['nullable', 'string', 'max:64'],
            'track_id' => ['required', 'integer'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->filled('token_id') && ! $this->filled('qr_hash')) {
                $validator->errors()->add('token_id', 'Either token_id or qr_hash is required.');
            }
        });
    }
}
