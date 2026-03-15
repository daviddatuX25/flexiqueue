<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/public/tts — query params: text (required), voice (optional), rate (optional).
 * Public (no auth); rate-limited by IP.
 */
class TtsStreamRequest extends FormRequest
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
            'text' => ['required', 'string', 'max:1000'],
            'voice' => ['sometimes', 'nullable', 'string', 'max:200'],
            'rate' => ['sometimes', 'numeric', 'min:0.5', 'max:2'],
        ];
    }
}
