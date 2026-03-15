<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Per flexiqueue-a3wh: POST /api/sessions/{session}/no-show.
 * Optional: enqueue_back, extend, last_call (booleans). When attempts >= max, exactly one of extend or last_call required.
 */
class MarkNoShowSessionRequest extends FormRequest
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
            'enqueue_back' => ['sometimes', 'boolean'],
            'extend' => ['sometimes', 'boolean'],
            'last_call' => ['sometimes', 'boolean'],
        ];
    }
}
