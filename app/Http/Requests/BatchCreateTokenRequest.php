<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Per 08-API-SPEC-PHASE1 §5.5: POST /api/admin/tokens/batch — prefix, count, start_number.
 */
class BatchCreateTokenRequest extends FormRequest
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
            'prefix' => ['required', 'string', 'max:10'],
            'count' => ['required', 'integer', 'min:1', 'max:500'],
            'start_number' => ['required', 'integer', 'min:0'],
            'pronounce_as' => ['sometimes', 'string', 'in:letters,word'],
        ];
    }
}
