<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Per 08-API-SPEC-PHASE1 §5.3: update station. Per PROCESS-STATION-REFACTOR §9.2: process_ids required.
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
            'process_ids' => [
                'required',
                'array',
                'min:1',
            ],
            'process_ids.*' => [
                'integer',
                Rule::exists('processes', 'id')->where('program_id', $station->program_id),
            ],
            'tts' => ['sometimes', 'array'],
            'tts.languages' => ['sometimes', 'array'],
            'tts.languages.en' => ['sometimes', 'array'],
            'tts.languages.en.voice_id' => ['nullable', 'string', 'max:200'],
            'tts.languages.en.rate' => ['nullable', 'numeric', 'between:0.5,2.0'],
            'tts.languages.en.station_phrase' => ['nullable', 'string', 'max:255'],
            'tts.languages.fil' => ['sometimes', 'array'],
            'tts.languages.fil.voice_id' => ['nullable', 'string', 'max:200'],
            'tts.languages.fil.rate' => ['nullable', 'numeric', 'between:0.5,2.0'],
            'tts.languages.fil.station_phrase' => ['nullable', 'string', 'max:255'],
            'tts.languages.ilo' => ['sometimes', 'array'],
            'tts.languages.ilo.voice_id' => ['nullable', 'string', 'max:200'],
            'tts.languages.ilo.rate' => ['nullable', 'numeric', 'between:0.5,2.0'],
            'tts.languages.ilo.station_phrase' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('is_active')) {
            $this->merge(['is_active' => $this->route('station')?->is_active ?? true]);
        }
    }
}
