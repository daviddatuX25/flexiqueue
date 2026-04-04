<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
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
        $email = fake()->unique()->safeEmail();

        return [
            'name' => fake()->name(),
            'email' => $email,
            'username' => strtolower(preg_replace('/[^a-z0-9._-]+/i', '_', str_replace('@', '_', $email))),
            'recovery_gmail' => null,
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'is_active' => true,
            'override_pin' => Hash::make('123456'),
            'override_qr_token' => Hash::make(Str::random(64)),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            if ($user->roles()->count() > 0) {
                return;
            }
            User::assignGlobalRoleAndSyncProvisioning($user, UserRole::Staff->value);
        });
    }

    /** Per 05-SECURITY-CONTROLS: admin role (redirect to admin.dashboard). */
    public function admin(): static
    {
        return $this->afterCreating(function (User $user): void {
            User::assignGlobalRoleAndSyncProvisioning($user, UserRole::Admin->value);
        });
    }

    /** Platform super_admin (global team). */
    public function superAdmin(): static
    {
        return $this->afterCreating(function (User $user): void {
            User::assignGlobalRoleAndSyncProvisioning($user, UserRole::SuperAdmin->value);
        });
    }

    /**
     * Creates staff user. For supervisor-like access, call supervisorForProgram($program) after create.
     * Per refactor: supervisor is program-specific, not a role.
     */
    public function supervisor(): static
    {
        return $this->afterCreating(function (User $user): void {
            User::assignGlobalRoleAndSyncProvisioning($user, UserRole::Staff->value);
        });
    }

    /** Per 05-SECURITY-CONTROLS §4: supervisor/admin with override PIN for override/force-complete. */
    public function withOverridePin(string $pin = '123456'): static
    {
        return $this->state(fn (array $attributes) => [
            'override_pin' => Hash::make($pin),
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
