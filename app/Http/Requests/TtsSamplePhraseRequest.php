<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/admin/tts/sample-phrase — query params for TTS sample phrase.
 */
class TtsSamplePhraseRequest extends FormRequest
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
            'lang' => ['required', 'string', 'in:en,fil,ilo'],
            'pre_phrase' => ['sometimes', 'nullable', 'string', 'max:500'],
            'alias' => ['sometimes', 'nullable', 'string', 'max:100'],
            'pronounce_as' => ['sometimes', 'string', 'in:letters,word,custom'],
            'token_phrase' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Get validated params with defaults for optional fields.
     */
    public function validatedSampleParams(): array
    {
        $v = $this->validated();

        return [
            'lang' => (string) $v['lang'],
            'pre_phrase' => isset($v['pre_phrase']) ? trim((string) $v['pre_phrase']) : '',
            'alias' => isset($v['alias']) ? trim((string) $v['alias']) : 'A1',
            'pronounce_as' => $v['pronounce_as'] ?? 'letters',
            'token_phrase' => isset($v['token_phrase']) && is_string($v['token_phrase']) && trim($v['token_phrase']) !== ''
                ? trim($v['token_phrase'])
                : null,
        ];
    }
}
