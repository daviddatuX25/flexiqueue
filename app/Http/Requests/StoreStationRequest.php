<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Per 08-API-SPEC-PHASE1 §5.3: create station — name, capacity. Stations = flow nodes only (triage separate).
 */
class StoreStationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $program = $this->route('program');
        $programId = $program?->id;

        return [
            'name' => [
                'required',
                'string',
                'max:50',
                $programId ? Rule::unique('stations', 'name')->where('program_id', $programId) : 'unique:stations,name',
            ],
            'capacity' => ['required', 'integer', 'min:1'],
            'client_capacity' => ['sometimes', 'integer', 'min:1'],
            'process_ids' => [
                'required',
                'array',
                'min:1',
            ],
            'process_ids.*' => [
                'integer',
                $program ? Rule::exists('processes', 'id')->where('program_id', $program->id) : 'exists:processes,id',
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
            'generate_tts' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('capacity')) {
            $this->merge(['capacity' => 1]);
        }
        if (! $this->has('client_capacity')) {
            $this->merge(['client_capacity' => 1]);
        }
    }
}
