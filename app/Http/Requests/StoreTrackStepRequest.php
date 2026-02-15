<?php

namespace App\Http\Requests;

use App\Models\ServiceTrack;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Per 08-API-SPEC-PHASE1 §5.4: add step to track. station_id must belong to track's program.
 */
class StoreTrackStepRequest extends FormRequest
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

        return [
            'station_id' => ['required', 'integer', Rule::in($track->program->stations()->pluck('id')->all())],
            'step_order' => ['nullable', 'integer', 'min:1'],
            'is_required' => ['nullable', 'boolean'],
            'estimated_minutes' => ['nullable', 'integer', 'min:0'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('is_required')) {
            $this->merge(['is_required' => true]);
        }
    }
}
