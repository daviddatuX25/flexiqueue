<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Per plan: supervisor/admin (or platform) can set station priority_first_override — not line staff alone.
 */
class SetStationPriorityFirstRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $station = $this->route('station');

        return $user && $station && $user->can('managePriority', $station);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'priority_first' => ['required', 'boolean'],
        ];
    }
}
