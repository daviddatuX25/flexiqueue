<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Per 08-API-SPEC-PHASE1 §5.1: update program — name required, max 100 chars.
 */
class UpdateProgramRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'settings' => ['sometimes', 'array'],
            'settings.no_show_timer_seconds' => ['sometimes', 'integer', 'min:5', 'max:600'],
            'settings.require_permission_before_override' => ['sometimes', 'boolean'],
            'settings.priority_first' => ['sometimes', 'boolean'],
            'settings.balance_mode' => ['sometimes', 'string', 'in:fifo,alternate'],
            'settings.station_selection_mode' => ['sometimes', 'string', 'in:fixed,shortest_queue,least_busy,round_robin,least_recently_served'],
            'settings.alternate_ratio' => ['sometimes', 'array'],
            'settings.alternate_ratio.0' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'settings.alternate_ratio.1' => ['sometimes', 'integer', 'min:1', 'max:10'],
            // Per bead flexiqueue-5gl: which queue is served first in alternate mode (default: priority first).
            'settings.alternate_priority_first' => ['sometimes', 'boolean'],
            'settings.display_scan_timeout_seconds' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:300'],
        ];
    }
}
