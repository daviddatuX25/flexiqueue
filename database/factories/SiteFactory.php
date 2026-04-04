<?php

namespace Database\Factories;

use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'           => fake()->company(),
            'slug'           => fake()->unique()->slug(2),
            'api_key_hash'   => hash('sha256', fake()->uuid()),
            'settings'       => [],
            'edge_settings'  => ['max_edge_devices' => 5],
            'is_default'     => false,
        ];
    }

    public function withMaxEdgeDevices(int $max): static
    {
        return $this->state(fn (array $attributes) => [
            'edge_settings' => array_merge($attributes['edge_settings'] ?? [], ['max_edge_devices' => $max]),
        ]);
    }
}
