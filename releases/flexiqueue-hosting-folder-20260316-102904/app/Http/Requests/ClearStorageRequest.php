<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClearStorageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'category' => ['required', 'string', 'in:tts_audio'],
        ];
    }
}
