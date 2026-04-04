<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTtsPlatformBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'global_enabled' => ['sometimes', 'boolean'],
            'period' => ['sometimes', Rule::in(['daily', 'monthly'])],
            'mode' => ['sometimes', Rule::in(['chars'])],
            'char_limit' => ['sometimes', 'integer', 'min:0'],
            'block_on_limit' => ['sometimes', 'boolean'],
            'warning_threshold_pct' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'weights' => ['sometimes', 'array'],
            'weights.*' => ['integer', 'min:1', 'max:1000000'],
        ];
    }
}
