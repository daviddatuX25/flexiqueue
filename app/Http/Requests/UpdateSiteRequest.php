<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Per central-edge B.5: update site — optional name, slug, edge_settings.
 * Per public-site plan: settings.public_access_key, landing_hero_*, landing_sections, landing_show_stats.
 * settings.landing_hero_image_path is never accepted here (set only via hero image upload endpoint).
 */
class UpdateSiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $site = $this->route('site');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => [
                'sometimes',
                'string',
                'max:100',
                'regex:/^[a-z0-9\-]+$/',
                $site ? Rule::unique('sites', 'slug')->ignore($site->id) : 'unique:sites,slug',
            ],
            'edge_settings' => ['sometimes', 'array'],
            'settings' => ['sometimes', 'array'],
            'settings.public_access_key' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-]+$/'],
            'settings.landing_hero_title' => ['nullable', 'string', 'max:120'],
            'settings.landing_hero_description' => ['nullable', 'string', 'max:500'],
            'settings.landing_sections' => ['nullable', 'array'],
            'settings.landing_sections.*.type' => ['required_with:settings.landing_sections.*', 'string', 'in:text'],
            'settings.landing_sections.*.title' => ['required_with:settings.landing_sections.*', 'string', 'max:200'],
            'settings.landing_sections.*.body' => ['nullable', 'string'],
            'settings.landing_show_stats' => ['nullable', 'boolean'],
            'settings.tts_budget' => ['sometimes', 'nullable', 'array'],
            'settings.tts_budget.enabled' => ['sometimes', 'boolean'],
            'settings.tts_budget.mode' => ['sometimes', 'string', 'in:chars'],
            'settings.tts_budget.period' => ['sometimes', 'string', 'in:daily,monthly'],
            'settings.tts_budget.limit' => ['sometimes', 'integer', 'min:0'],
            'settings.tts_budget.warning_threshold_pct' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'settings.tts_budget.block_on_limit' => ['sometimes', 'boolean'],
        ];
    }
}
