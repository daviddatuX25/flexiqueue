<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePrintSettingsRequest extends FormRequest
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
            'cards_per_page' => ['sometimes', 'integer', 'min:4', 'max:8'],
            'paper' => ['sometimes', 'string', 'in:a4,letter'],
            'orientation' => ['sometimes', 'string', 'in:portrait,landscape'],
            'show_hint' => ['sometimes', 'boolean'],
            'show_cut_lines' => ['sometimes', 'boolean'],
            'logo_url' => ['nullable', 'string', 'max:500'],
            'footer_text' => ['nullable', 'string', 'max:1000'],
            'bg_image_url' => ['nullable', 'string', 'max:500'],
        ];
    }
}
