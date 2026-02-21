<?php

namespace App\Http\Requests;

use App\Models\Program;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Per PROCESS-STATION-REFACTOR §9.1: Create process. Name unique per program.
 */
class StoreProcessRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:50', Rule::unique('processes', 'name')->where('program_id', $program->id)],
            'description' => ['nullable', 'string'],
        ];
    }
}
