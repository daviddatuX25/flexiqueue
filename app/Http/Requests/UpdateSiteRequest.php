<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Per central-edge B.5: update site — optional name, slug (unique except current), edge_settings.
 * edge_settings validated via EdgeSettingsValidator in controller.
 */
class UpdateSiteRequest extends FormRequest
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
        ];
    }
}
