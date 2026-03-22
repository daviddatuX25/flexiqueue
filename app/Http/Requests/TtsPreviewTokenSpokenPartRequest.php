<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TtsPreviewTokenSpokenPartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'lang' => ['required', 'string', 'in:en,fil,ilo'],
            'alias' => ['required', 'string', 'max:50'],
            'pronounce_as' => ['required', 'string', 'in:letters,word,custom'],
            'token_phrase' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
