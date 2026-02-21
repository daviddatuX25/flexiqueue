<?php

namespace App\Http\Requests;

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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
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
