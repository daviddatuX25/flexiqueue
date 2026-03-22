<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTokenTtsSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Allow any authenticated user that has already passed route middleware checks.
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'voice_id' => ['nullable', 'string', 'max:200'],
            'rate' => ['nullable', 'numeric', 'min:0.5', 'max:2.0'],
            'playback' => ['sometimes', 'array'],
            'playback.prefer_generated_audio' => ['sometimes', 'boolean'],
            'playback.allow_custom_pronunciation' => ['sometimes', 'boolean'],
            'playback.segment_2_enabled' => ['sometimes', 'boolean'],
            'languages' => ['sometimes', 'array'],
            'languages.en' => ['sometimes', 'array'],
            'languages.en.voice_id' => ['nullable', 'string', 'max:200'],
            'languages.en.rate' => ['nullable', 'numeric', 'min:0.5', 'max:2.0'],
            'languages.en.pre_phrase' => ['nullable', 'string', 'max:255'],
            'languages.en.token_phrase' => ['nullable', 'string', 'max:255'],
            'languages.en.token_bridge_tail' => ['nullable', 'string', 'max:500'],
            'languages.en.closing_without_segment2' => ['nullable', 'string', 'max:500'],
            'languages.fil' => ['sometimes', 'array'],
            'languages.fil.voice_id' => ['nullable', 'string', 'max:200'],
            'languages.fil.rate' => ['nullable', 'numeric', 'min:0.5', 'max:2.0'],
            'languages.fil.pre_phrase' => ['nullable', 'string', 'max:255'],
            'languages.fil.token_phrase' => ['nullable', 'string', 'max:255'],
            'languages.fil.token_bridge_tail' => ['nullable', 'string', 'max:500'],
            'languages.fil.closing_without_segment2' => ['nullable', 'string', 'max:500'],
            'languages.ilo' => ['sometimes', 'array'],
            'languages.ilo.voice_id' => ['nullable', 'string', 'max:200'],
            'languages.ilo.rate' => ['nullable', 'numeric', 'min:0.5', 'max:2.0'],
            'languages.ilo.pre_phrase' => ['nullable', 'string', 'max:255'],
            'languages.ilo.token_phrase' => ['nullable', 'string', 'max:255'],
            'languages.ilo.token_bridge_tail' => ['nullable', 'string', 'max:500'],
            'languages.ilo.closing_without_segment2' => ['nullable', 'string', 'max:500'],
        ];
    }
}
