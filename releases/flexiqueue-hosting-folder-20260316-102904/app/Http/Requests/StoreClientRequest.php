<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreClientRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:150'],
            'birth_year' => ['required', 'integer', 'min:1900', 'max:'.now()->year],
            'id_document' => ['nullable', 'array'],
            'id_document.id_type' => ['required_with:id_document', 'string', 'max:50'],
            'id_document.id_number' => ['required_with:id_document', 'string', 'max:255'],
        ];
    }
}

