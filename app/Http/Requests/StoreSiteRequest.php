<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Per central-edge B execution plan: create site — name and slug required; slug unique.
 * edge_settings validation is B.3; here we accept array or leave default.
 */
class StoreSiteRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9\-]+$/', Rule::unique('sites', 'slug')],
            'settings' => ['nullable', 'array'],
            'edge_settings' => ['nullable', 'array'],
        ];
    }
}
