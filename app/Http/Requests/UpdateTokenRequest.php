<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Per plan: PUT /api/admin/tokens/{id} — status (available only).
 * in_use is set by bind flow; admin can only set to available. Lost/damaged replaced by soft delete.
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
            'status' => ['required', 'string', 'in:available'],
        ];
    }
}
