<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Profile avatar update. Client compresses to ~50KB; 100KB max (jpeg/png).
 */
class UpdateAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'avatar' => ['required', 'image', 'mimes:jpeg,jpg,png', 'max:100'],
        ];
    }
}
