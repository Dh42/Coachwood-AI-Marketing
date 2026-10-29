<?php

namespace Database\Factories;

use App\Auth\Role;
use App\Models\RoleAssignment;
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
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Give the user a platform-wide role.
     */
    public function withRole(Role $role): static
    {
        return $this->afterCreating(fn (User $user) => $user->roleAssignments()->create([
            'role' => $role,
            'brand_id' => RoleAssignment::PLATFORM,
        ]));
    }

    public function admin(): static
    {
        return $this->withRole(Role::Admin);
    }

    /**
     * A company account that signs in with Google and has no password.
     */
    public function google(): static
    {
        return $this->state(fn (array $attributes) => [
            'google_id' => (string) fake()->unique()->randomNumber(9),
            'password' => null,
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => ['disabled_at' => now()]);
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
