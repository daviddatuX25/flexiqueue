<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Per 08-API-SPEC-PHASE1 §5.3: update station. Unique name per program (excluding self).
 */
class UpdateStationRequest extends FormRequest
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
        $station = $this->route('station');

        return [
            'name' => [
                'required',
                'string',
                'max:50',
                Rule::unique('stations', 'name')
                    ->where('program_id', $station->program_id)
                    ->ignore($station->id),
            ],
            'capacity' => ['required', 'integer', 'min:1'],
            'client_capacity' => ['sometimes', 'integer', 'min:1'],
            'priority_first_override' => ['sometimes', 'nullable', 'boolean'],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('is_active')) {
            $this->merge(['is_active' => $this->route('station')?->is_active ?? true]);
        }
    }
}
