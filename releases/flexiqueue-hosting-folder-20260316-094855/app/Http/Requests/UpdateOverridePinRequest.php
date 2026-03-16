<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Per PIN-QR-AUTHORIZATION-SYSTEM AUTH-2: User sets/updates preset PIN in Profile.
 * Body: current_password, new_pin (6 digits). Never expose to admin.
 */
class UpdateOverridePinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $pin = $this->input('new_pin');
        if (is_string($pin)) {
            $this->merge(['new_pin' => trim($pin)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
            'new_pin' => ['required', 'string', 'size:6', 'regex:/^\d{6}$/'],
        ];
    }
}
