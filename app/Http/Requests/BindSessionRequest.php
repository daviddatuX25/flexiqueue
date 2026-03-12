<?php

namespace App\Http\Requests;

use App\Support\ClientBindingSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // Allow identity_registration_request-only submissions (no token) for public/staff triage flows.
            'qr_hash' => ['required_without:identity_registration_request', 'string', 'max:64'],
            'track_id' => ['required_with:qr_hash', 'integer'],
            'client_category' => ['nullable', 'string', 'max:50'],
            'client_binding' => ['nullable', 'array'],
            'client_binding.client_id' => ['required_with:client_binding', 'integer', 'exists:clients,id'],
            'client_binding.source' => ClientBindingSource::validationRules(),
            'client_binding.id_document_id' => [
                'nullable',
                'integer',
                'exists:client_id_documents,id',
                Rule::requiredIf(fn () => ClientBindingSource::requiresIdDocument(
                    (string) $this->input('client_binding.source', '')
                )),
            ],
            'identity_registration_request' => ['nullable', 'array'],
            'identity_registration_request.name' => ['nullable', 'string', 'max:150'],
            'identity_registration_request.birth_year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'identity_registration_request.client_category' => ['nullable', 'string', 'max:50'],
            'identity_registration_request.id_type' => ['nullable', 'string', 'max:50'],
            'identity_registration_request.id_number' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Ensure client_binding and identity_registration_request are mutually exclusive.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $hasBinding = $this->filled('client_binding');
            $hasRegistration = $this->filled('identity_registration_request');
            if ($hasBinding && $hasRegistration) {
                $validator->errors()->add('identity_registration_request', 'Cannot send both client_binding and identity_registration_request.');
            }
        });
    }
}
