<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClientSearchRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'birth_date' => ['sometimes', 'nullable', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * Get validated data with defaults for pagination.
     *
     * @return array{name: string, birth_date: ?string, per_page: int, page: int}
     */
    public function validatedSearchParams(): array
    {
        $validated = $this->validated();

        return [
            'name' => trim((string) $validated['name']),
            'birth_date' => isset($validated['birth_date']) && $validated['birth_date'] !== '' ? (string) $validated['birth_date'] : null,
            'per_page' => (int) ($validated['per_page'] ?? 3),
            'page' => (int) ($validated['page'] ?? 1),
        ];
    }
}
