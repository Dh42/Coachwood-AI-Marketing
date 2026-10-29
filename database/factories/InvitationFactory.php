<?php

namespace Database\Factories;

use App\Auth\Role;
use App\Models\Invitation;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'role' => Role::Viewer,
            'brand_id' => RoleAssignment::PLATFORM,
            'token_hash' => Invitation::hashToken(Invitation::newToken()),
            'invited_by' => User::factory(),
            'expires_at' => now()->addDays(7),
        ];
    }
}
