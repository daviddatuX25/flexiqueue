<?php

namespace App\Http\Requests;

use App\Models\Program;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Per PROCESS-STATION-REFACTOR §9.2: Set station processes. Must have ≥1.
 */
class SetStationProcessesRequest extends FormRequest
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
        /** @var Program $program */
        $program = $this->route('program');

        return [
            'process_ids' => [
                'required',
                'array',
                'min:1',
            ],
            'process_ids.*' => [
                'integer',
                Rule::exists('processes', 'id')->where('program_id', $program->id),
            ],
        ];
    }
}
