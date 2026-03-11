<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Per 08-API-SPEC-PHASE1 §3.1: POST /api/sessions/bind — qr_hash, track_id, client_category.
 */
class BindSessionRequest extends FormRequest
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
            'qr_hash' => ['required', 'string', 'max:64'],
            'track_id' => ['required', 'integer'],
            'client_category' => ['nullable', 'string', 'max:50'],
            'client_binding' => ['nullable', 'array'],
            'client_binding.client_id' => ['required_with:client_binding', 'integer', 'exists:clients,id'],
            'client_binding.source' => ['required_with:client_binding', 'string', 'max:50'],
            'client_binding.id_document_id' => ['required_with:client_binding', 'integer', 'exists:client_id_documents,id'],
        ];
    }
}
