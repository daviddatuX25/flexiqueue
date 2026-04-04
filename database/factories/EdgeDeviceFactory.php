<?php

namespace Database\Factories;

use App\Models\EdgeDevice;
use App\Models\Program;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EdgeDevice>
 */
class EdgeDeviceFactory extends Factory
{
    public function definition(): array
    {
        $plainToken = 'test-token-' . fake()->unique()->uuid();

        return [
            'site_id'                  => Site::factory(),
            'name'                     => 'Pi-' . fake()->numberBetween(1000, 9999),
            'device_token_hash'        => hash('sha256', $plainToken),
            'id_offset'                => 10_000_000,
            'sync_mode'                => 'auto',
            'supervisor_admin_access'  => false,
            'assigned_program_id'      => null,
            'session_active'           => false,
            'paired_at'                => now(),
            'revoked_at'               => null,
        ];
    }

    public function assignedTo(Program $program): static
    {
        return $this->state(fn (array $attributes) => [
            'site_id'             => $program->site_id,
            'assigned_program_id' => $program->id,
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'revoked_at' => now(),
        ]);
    }
}
