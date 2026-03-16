<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => 'staff',
            'is_active' => true,
            'override_pin' => Hash::make('123456'),
            'override_qr_token' => Hash::make(Str::random(64)),
        ];
    }

    /** Per 05-SECURITY-CONTROLS: admin role (redirect to admin.dashboard). */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => ['role' => 'admin']);
    }

    /**
     * Creates staff user. For supervisor-like access, call supervisorForProgram($program) after create.
     * Per refactor: supervisor is program-specific, not a role.
     */
    public function supervisor(): static
    {
        return $this->state(fn (array $attributes) => ['role' => 'staff']);
    }

    /** Per 05-SECURITY-CONTROLS §4: supervisor/admin with override PIN for override/force-complete. */
    public function withOverridePin(string $pin = '123456'): static
    {
        return $this->state(fn (array $attributes) => [
            'override_pin' => \Illuminate\Support\Facades\Hash::make($pin),
        ]);
    }

    /** Inactive user cannot log in (LoginController checks is_active). */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
