<?php

namespace Tests\Unit\Validation;

use App\Validation\EdgeSettingsValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EdgeSettingsValidatorTest extends TestCase
{
    use RefreshDatabase;

    private EdgeSettingsValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new EdgeSettingsValidator();
    }

    public function test_empty_payload_uses_all_default_values(): void
    {
        $result = $this->validator->validate([]);

        $this->assertSame([
            'bridge_enabled' => false,
            'max_edge_devices' => 0,
            'offline_allow_client_creation' => true,
            'offline_binding_mode_override' => 'optional',
            'scheduled_sync_time' => '17:00',
            'sync_client_scope' => 'program_history',
            'sync_clients' => true,
            'sync_tokens' => true,
            'sync_tts' => true,
        ], $result);
    }

    public function test_full_valid_payload_is_returned_unchanged_except_for_order(): void
    {
        $payload = [
            'sync_clients' => false,
            'sync_client_scope' => 'all',
            'sync_tokens' => false,
            'sync_tts' => false,
            'bridge_enabled' => true,
            'offline_binding_mode_override' => 'required',
            'scheduled_sync_time' => '09:30',
            'offline_allow_client_creation' => false,
            'max_edge_devices' => 5,
        ];

        $result = $this->validator->validate($payload);

        ksort($payload);

        $this->assertSame($payload, $result);
    }

    public function test_unknown_key_raises_validation_exception(): void
    {
        $this->expectException(ValidationException::class);

        $this->validator->validate([
            'sync_clients' => true,
            'unknown_key' => 'value',
        ]);
    }

    public function test_invalid_enum_value_raises_validation_exception(): void
    {
        $this->expectException(ValidationException::class);

        $this->validator->validate([
            'sync_client_scope' => 'invalid-scope',
        ]);
    }

    public function test_invalid_type_raises_validation_exception(): void
    {
        $this->expectException(ValidationException::class);

        $this->validator->validate([
            'sync_clients' => 'yes',
        ]);
    }

    public function test_invalid_scheduled_sync_time_format_raises_validation_exception(): void
    {
        $this->expectException(ValidationException::class);

        $this->validator->validate([
            'scheduled_sync_time' => '9:00',
        ]);
    }

    public function test_out_of_range_scheduled_sync_time_raises_validation_exception(): void
    {
        $this->expectException(ValidationException::class);

        $this->validator->validate([
            'scheduled_sync_time' => '24:01',
        ]);
    }
}

