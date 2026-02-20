<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Per staff-availability-status plan: PATCH /api/users/me/availability.
 * Body: status = available | on_break | away.
 */
class UpdateUserAvailabilityRequest extends FormRequest
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
            'status' => ['required', 'string', 'in:available,on_break,away'],
        ];
    }
}
