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
        ];
    }
}

