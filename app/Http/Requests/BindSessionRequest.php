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
        ];
    }
}
