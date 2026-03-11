<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Per ISSUES-ELABORATION §2: update global default program settings.
 * Same rules as UpdateProgramRequest for the settings subset.
 */
class UpdateProgramDefaultSettingsRequest extends FormRequest
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
            'settings' => ['required', 'array'],
            'settings.no_show_timer_seconds' => ['sometimes', 'integer', 'min:5', 'max:600'],
            'settings.max_no_show_attempts' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'settings.require_permission_before_override' => ['sometimes', 'boolean'],
            'settings.priority_first' => ['sometimes', 'boolean'],
            'settings.balance_mode' => ['sometimes', 'string', 'in:fifo,alternate'],
            'settings.station_selection_mode' => ['sometimes', 'string', 'in:fixed,shortest_queue,least_busy,round_robin,least_recently_served'],
            'settings.alternate_ratio' => ['sometimes', 'array'],
            'settings.alternate_ratio.0' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'settings.alternate_ratio.1' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ];
    }
}
