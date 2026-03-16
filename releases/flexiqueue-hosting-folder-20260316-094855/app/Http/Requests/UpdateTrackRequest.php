<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Per 08-API-SPEC-PHASE1 §5.2: update track. Unique name per program (excluding self).
 */
class UpdateTrackRequest extends FormRequest
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
        $track = $this->route('service_track');

        return [
            'name' => [
                'required',
                'string',
                'max:50',
                Rule::unique('service_tracks', 'name')
                    ->where('program_id', $track->program_id)
                    ->ignore($track->id),
            ],
            'description' => ['nullable', 'string'],
            'is_default' => ['boolean'],
            'color_code' => ['nullable', 'string', 'max:7'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('is_default')) {
            $this->merge(['is_default' => $this->route('service_track')?->is_default ?? false]);
        }
    }
}
