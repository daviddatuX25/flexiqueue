<?php

namespace App\Http\Requests;

use App\Support\ClientBindingSource;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Per 08-API-SPEC-PHASE1 §3.1: POST /api/sessions/bind — qr_hash, track_id, client_category.
 * Optional: priority_lane_override (boolean) only when client_category is an "Other: …" label — see docs/architecture/08-API-SPEC-PHASE1.md.
 */
class BindSessionRequest extends FormRequest
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
            'program_id' => ['nullable', 'integer', 'exists:programs,id'],
            // Allow identity_registration_request-only submissions (no token) for public/staff triage flows.
            'qr_hash' => ['required_without:identity_registration_request', 'string', 'max:64'],
            'track_id' => ['required_with:qr_hash', 'integer'],
            'client_category' => ['nullable', 'string', 'max:50'],
            'priority_lane_override' => ['sometimes', 'boolean'],
            'client_binding' => ['nullable', 'array'],
            'client_binding.client_id' => ['required_with:client_binding', 'integer', 'exists:clients,id'],
            'client_binding.source' => ClientBindingSource::validationRules(),
            'identity_registration_request' => ['nullable', 'array'],
            'identity_registration_request.first_name' => ['nullable', 'string', 'max:100'],
            'identity_registration_request.middle_name' => ['nullable', 'string', 'max:100'],
            'identity_registration_request.last_name' => ['nullable', 'string', 'max:100'],
            'identity_registration_request.birth_date' => ['nullable', 'date'],
            'identity_registration_request.address_line_1' => ['nullable', 'string', 'max:255'],
            'identity_registration_request.address_line_2' => ['nullable', 'string', 'max:255'],
            'identity_registration_request.city' => ['nullable', 'string', 'max:100'],
            'identity_registration_request.state' => ['nullable', 'string', 'max:100'],
            'identity_registration_request.postal_code' => ['nullable', 'string', 'max:20'],
            'identity_registration_request.country' => ['nullable', 'string', 'max:100'],
            'identity_registration_request.client_category' => ['nullable', 'string', 'max:50'],
            'identity_registration_request.mobile' => ['nullable', 'string', 'max:30'],
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

            if (array_key_exists('priority_lane_override', $this->all())) {
                $cat = $this->input('client_category');
                $trimmed = is_string($cat) ? trim($cat) : '';
                $isOther = str_starts_with(strtolower($trimmed), 'other:');
                if (! $isOther) {
                    $validator->errors()->add(
                        'priority_lane_override',
                        'Priority lane override is only allowed when client_category starts with "Other:".'
                    );
                }
            }
        });
    }
}
