<?php

namespace App\Http\Requests;

use App\Services\ElevenLabsClient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreTtsAccountRequest extends FormRequest
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
            'label' => ['required', 'string', 'max:255'],
            'api_key' => ['required', 'string', 'min:10'],
            'model_id' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $key = $this->input('api_key', '');
            if ($key === '') {
                return;
            }

            $client = new ElevenLabsClient($key);
            if (! $client->validateKey()) {
                $validator->errors()->add('api_key', 'The API key could not be validated with ElevenLabs. Please check it and try again.');
            }
        });
    }

    protected function passedValidation(): void
    {
        // Normalize model_id default
        $modelId = $this->input('model_id');
        if ($modelId === null || trim($modelId) === '') {
            $this->merge(['model_id' => 'eleven_multilingual_v2']);
        }
    }
}
