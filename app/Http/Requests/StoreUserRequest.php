<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Per 08-API-SPEC-PHASE1 §5.6: create user — name, email, password, role, override_pin.
 */
class StoreUserRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'role' => ['required', 'string', Rule::enum(UserRole::class)],
            'override_pin' => ['nullable', 'string', 'size:6', 'regex:/^\d{6}$/'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $pin = $this->override_pin;
        if (is_string($pin) && $pin !== '') {
            $this->merge(['override_pin' => trim($pin)]);
        }
    }
}
