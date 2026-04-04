<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/admin/tts/preview-text — builder-backed phrase for admin UI (no client-side join drift).
 */
class TtsPreviewTextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'segment' => ['required', 'integer', 'in:1,2'],
            'lang' => ['required', 'string', 'in:en,fil,ilo'],
            'alias' => ['sometimes', 'nullable', 'string', 'max:100'],
            'pronounce_as' => ['sometimes', 'string', 'in:letters,word,custom'],
            'pre_phrase' => ['sometimes', 'nullable', 'string', 'max:500'],
            'token_phrase' => ['sometimes', 'nullable', 'string', 'max:255'],
            'token_bridge_tail' => ['sometimes', 'nullable', 'string', 'max:500'],
            'connector_phrase' => ['sometimes', 'nullable', 'string', 'max:500'],
            'station_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'station_phrase' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
