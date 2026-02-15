<?php

namespace App\Http\Requests;

use App\Models\ServiceTrack;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Per 08-API-SPEC-PHASE1 §5.4: reorder steps. step_ids must be all and only this track's step ids.
 */
class ReorderTrackStepsRequest extends FormRequest
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
        /** @var ServiceTrack $track */
        $track = $this->route('track');
        $stepIds = $track->trackSteps()->pluck('id')->all();

        return [
            'step_ids' => ['required', 'array'],
            'step_ids.*' => ['integer', Rule::in($stepIds)],
            'migrate_sessions' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Ensure step_ids contains each step exactly once (no duplicates, no missing).
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            /** @var ServiceTrack $track */
            $track = $this->route('track');
            $expected = $track->trackSteps()->pluck('id')->sort()->values()->all();
            $given = collect($this->input('step_ids', []))->sort()->values()->all();
            if ($expected !== $given) {
                $validator->errors()->add('step_ids', 'step_ids must contain each step ID of this track exactly once.');
            }
        });
    }
}
