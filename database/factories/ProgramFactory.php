<?php

namespace Database\Factories;

use App\Models\Program;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Program>
 */
class ProgramFactory extends Factory
{
    public function definition(): array
    {
        return [
            'site_id'                   => Site::factory(),
            'name'                      => fake()->words(3, true),
            'slug'                      => null,
            'description'               => fake()->sentence(),
            'is_active'                 => true,
            'is_paused'                => false,
            'is_published'             => true,
            'settings'                  => [],
            'created_by'                => User::factory(),
            'edge_locked_by_device_id'  => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
