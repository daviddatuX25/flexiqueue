<?php

namespace App\Validation;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class EdgeSettingsValidator
{
    /**
     * Validate and normalize the given edge settings payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validate(array $payload): array
    {
        $allowedKeys = [
            'sync_clients',
            'sync_client_scope',
            'sync_tokens',
            'sync_tts',
            'bridge_enabled',
            'offline_binding_mode_override',
            'scheduled_sync_time',
            'offline_allow_client_creation',
            'max_edge_devices',
        ];

        $defaults = [
            'sync_clients' => true,
            'sync_client_scope' => 'program_history',
            'sync_tokens' => true,
            'sync_tts' => true,
            'bridge_enabled' => false,
            'offline_binding_mode_override' => 'optional',
            'scheduled_sync_time' => '17:00',
            'offline_allow_client_creation' => true,
            'max_edge_devices' => 0,
        ];

        $validator = Validator::make(
            ['edge_settings' => $payload],
            [
                'edge_settings' => ['array'],
                'edge_settings.sync_clients' => ['sometimes', 'boolean'],
                'edge_settings.sync_client_scope' => ['sometimes', 'string', 'in:program_history,all'],
                'edge_settings.sync_tokens' => ['sometimes', 'boolean'],
                'edge_settings.sync_tts' => ['sometimes', 'boolean'],
                'edge_settings.bridge_enabled' => ['sometimes', 'boolean'],
                'edge_settings.offline_binding_mode_override' => ['sometimes', 'string', 'in:optional,required'],
                'edge_settings.scheduled_sync_time' => ['sometimes', 'string'],
                'edge_settings.offline_allow_client_creation' => ['sometimes', 'boolean'],
                'edge_settings.max_edge_devices' => ['sometimes', 'integer', 'min:0', 'max:100'],
            ]
        );

        $validator->after(function ($validator) use ($payload, $allowedKeys): void {
            $unknownKeys = array_diff(array_keys($payload), $allowedKeys);

            foreach ($unknownKeys as $key) {
                $validator->errors()->add("edge_settings.$key", "Unknown key '{$key}' in edge_settings.");
            }

            if (array_key_exists('scheduled_sync_time', $payload)) {
                $value = $payload['scheduled_sync_time'];

                if (! is_string($value) || ! preg_match('/^\d{2}:\d{2}$/', $value)) {
                    $validator->errors()->add(
                        'edge_settings.scheduled_sync_time',
                        'The scheduled_sync_time must be in HH:MM 24-hour format.'
                    );

                    return;
                }

                [$hour, $minute] = array_map('intval', explode(':', $value));

                if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
                    $validator->errors()->add(
                        'edge_settings.scheduled_sync_time',
                        'The scheduled_sync_time must be a valid 24-hour time.'
                    );
                }
            }
        });

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $normalized = array_merge($defaults, Arr::only($payload, $allowedKeys));

        ksort($normalized);

        return $normalized;
    }
}

