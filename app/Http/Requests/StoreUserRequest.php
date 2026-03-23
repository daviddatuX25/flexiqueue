<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Per 08-API-SPEC-PHASE1 §5.6 + HYBRID_AUTH_ADMIN_FIRST_PRD ONB-1: name, username, email, recovery_gmail, password, role, override_pin.
 */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9._-]+$/', 'unique:users,username'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'recovery_gmail' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'role' => ['required', 'string', Rule::enum(UserRole::class)],
            'override_pin' => ['nullable', 'string', 'size:6', 'regex:/^\d{6}$/'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'pending_assignment' => ['sometimes', 'boolean'],
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
