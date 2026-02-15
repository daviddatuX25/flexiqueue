<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Per plan: staff (supervisor/admin) can set station priority_first_override.
 */
class SetStationPriorityFirstRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $station = $this->route('station');
        return $user && $station && ($user->isAdmin() || $user->isSupervisorForProgram($station->program_id));
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'priority_first' => ['required', 'boolean'],
        ];
    }
}
