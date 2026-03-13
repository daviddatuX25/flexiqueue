<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Per central-edge C.3.1: POST /api/admin/programs/{program}/tokens
 * Body: { "token_id": <id> } or { "token_ids": [<id>, ...] }. Idempotent via syncWithoutDetaching.
 */
class AssignTokensRequest extends FormRequest
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
            'token_id' => [
                'required_without:token_ids',
                'nullable',
                'integer',
                'exists:tokens,id',
            ],
            'token_ids' => [
                'required_without:token_id',
                'nullable',
                'array',
                'min:1',
            ],
            'token_ids.*' => ['integer', 'exists:tokens,id'],
        ];
    }

    /**
     * Return normalized list of token IDs (one or many).
     *
     * @return array<int, int>
     */
    public function getTokenIds(): array
    {
        if ($this->filled('token_id')) {
            return [(int) $this->input('token_id')];
        }

        return array_map('intval', $this->input('token_ids', []));
    }
}
