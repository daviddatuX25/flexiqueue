<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Support\PermissionCatalog;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Per 08-API-SPEC-PHASE1 §5.6: update user.
 */
class UpdateUserRequest extends FormRequest
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
        $user = $this->route('user');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
            'role' => ['sometimes', 'required', 'string', Rule::enum(UserRole::class)],
            'is_active' => ['sometimes', 'boolean'],
            'override_pin' => ['nullable', 'string', 'size:6', 'regex:/^\d{6}$/'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'direct_permissions' => ['sometimes', 'array'],
            'direct_permissions.*' => ['string', Rule::in(PermissionCatalog::assignableDirect())],
        ];
    }

    protected function prepareForValidation(): void
    {
        $pin = $this->override_pin ?? null;
        if ($pin === '' || $pin === null) {
            $this->merge(['override_pin' => null]);
        } elseif (is_string($pin)) {
            $this->merge(['override_pin' => trim($pin)]);
        }
    }
}
