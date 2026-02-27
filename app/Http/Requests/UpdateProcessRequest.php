<?php

namespace App\Http\Requests;

use App\Models\Process;
use App\Models\Program;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Per ISSUES-ELABORATION §19: Update process. Name required, max length, unique per program (excluding current).
 */
class UpdateProcessRequest extends FormRequest
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
        /** @var Process $process */
        $process = $this->route('process');

        return [
            'name' => [
                'required',
                'string',
                'max:50',
                Rule::unique('processes', 'name')
                    ->where('program_id', $program->id)
                    ->ignore($process->id),
            ],
            'description' => ['nullable', 'string'],
            // Per flexiqueue-5l7: expected time in seconds; max 600 (10 min) for single process step
            'expected_time_seconds' => ['nullable', 'integer', 'min:0', 'max:600'],
        ];
    }
}
