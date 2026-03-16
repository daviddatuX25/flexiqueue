<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Per addition-to-public-site-plan Part 5.1: program key validation (POST /api/public/program-key).
 */
class PublicProgramKeyRequest extends FormRequest
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
            'site_slug' => ['required', 'string', 'max:100'],
            'key' => ['required', 'string', 'max:50'],
            'program_slug' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
