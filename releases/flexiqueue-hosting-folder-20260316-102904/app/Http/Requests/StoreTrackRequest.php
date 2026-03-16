<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Per 08-API-SPEC-PHASE1 §5.2: create track — name, description, is_default, color_code.
 * Per 04-DATA-MODEL Table 2: name max 50, unique per program.
 */
class StoreTrackRequest extends FormRequest
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
        $programId = $this->route('program')?->id;

        return [
            'name' => [
                'required',
                'string',
                'max:50',
                $programId ? Rule::unique('service_tracks', 'name')->where('program_id', $programId) : 'unique:service_tracks,name',
            ],
            'description' => ['nullable', 'string'],
            'is_default' => ['boolean'],
            'color_code' => ['nullable', 'string', 'max:7'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('is_default')) {
            $this->merge(['is_default' => false]);
        }
    }
}
