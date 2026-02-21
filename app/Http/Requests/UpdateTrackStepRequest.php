<?php

namespace App\Http\Requests;

use App\Models\TrackStep;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Per 08-API-SPEC-PHASE1 §5.4, PROCESS-STATION-REFACTOR Phase 3: update step. process_id must belong to step's track program.
 */
class UpdateTrackStepRequest extends FormRequest
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
        /** @var TrackStep $step */
        $step = $this->route('step');
        $processIds = $step->serviceTrack->program->processes()->pluck('id')->all();

        return [
            'process_id' => ['sometimes', 'integer', Rule::in($processIds)],
            'step_order' => ['sometimes', 'integer', 'min:1'],
            'is_required' => ['sometimes', 'boolean'],
            'estimated_minutes' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
