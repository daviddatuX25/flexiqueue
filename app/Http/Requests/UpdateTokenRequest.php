<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Per plan: PUT /api/admin/tokens/{id} — status.
 * in_use is set by bind flow. Admin can set available (reactivate) or deactivated.
 */
class UpdateTokenRequest extends FormRequest
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
            'status' => ['sometimes', 'required', 'string', 'in:available,deactivated'],
            'pronounce_as' => ['sometimes', 'required', 'string', 'in:letters,word'],
        ];
    }
}
