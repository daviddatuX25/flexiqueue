<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Per program diagram plan 2.5: validate image upload for diagram decoration (max 2MB, jpeg/png).
 */
class StoreDiagramImageRequest extends FormRequest
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
            'image' => ['required', 'image', 'mimes:jpeg,jpg,png', 'max:2048'],
        ];
    }
}
