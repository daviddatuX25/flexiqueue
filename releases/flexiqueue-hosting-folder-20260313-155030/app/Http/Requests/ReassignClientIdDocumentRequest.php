<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReassignClientIdDocumentRequest extends FormRequest
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
            'target_client_id' => ['required', 'integer', 'exists:clients,id'],
        ];
    }
}

