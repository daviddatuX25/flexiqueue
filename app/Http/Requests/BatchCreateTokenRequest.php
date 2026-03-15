<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Per 08-API-SPEC-PHASE1 §5.5: POST /api/admin/tokens/batch — prefix, count, start_number.
 */
class BatchCreateTokenRequest extends FormRequest
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
            'prefix' => ['required', 'string', 'max:10'],
            'count' => ['required', 'integer', 'min:1', 'max:500'],
            'start_number' => ['required', 'integer', 'min:0'],
            'pronounce_as' => ['sometimes', 'string', 'in:letters,word'],
            'is_global' => ['sometimes', 'boolean'],
            // Legacy flag kept for backwards-compat; generation is now always enabled when server TTS is available.
            'generate_tts' => ['sometimes', 'boolean'],
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
