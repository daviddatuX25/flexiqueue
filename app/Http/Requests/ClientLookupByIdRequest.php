<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClientLookupByIdRequest extends FormRequest
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
            'id_type' => ['nullable', 'string', 'max:50'],
            'id_number' => ['required', 'string', 'max:255'],
        ];
    }
}

