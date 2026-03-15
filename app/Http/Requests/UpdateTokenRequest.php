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
            'is_global' => ['sometimes', 'boolean'],
            'tts' => ['sometimes', 'array'],
            'tts.en' => ['sometimes', 'array'],
            'tts.en.voice_id' => ['nullable', 'string', 'max:200'],
            'tts.en.rate' => ['nullable', 'numeric', 'between:0.5,2.0'],
            'tts.en.pre_phrase' => ['nullable', 'string', 'max:255'],
            'tts.fil' => ['sometimes', 'array'],
            'tts.fil.voice_id' => ['nullable', 'string', 'max:200'],
            'tts.fil.rate' => ['nullable', 'numeric', 'between:0.5,2.0'],
            'tts.fil.pre_phrase' => ['nullable', 'string', 'max:255'],
            'tts.ilo' => ['sometimes', 'array'],
            'tts.ilo.voice_id' => ['nullable', 'string', 'max:200'],
            'tts.ilo.rate' => ['nullable', 'numeric', 'between:0.5,2.0'],
            'tts.ilo.pre_phrase' => ['nullable', 'string', 'max:255'],
        ];
    }
}
