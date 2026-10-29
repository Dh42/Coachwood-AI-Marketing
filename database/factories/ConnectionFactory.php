<?php

namespace Database\Factories;

use App\Models\Connection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Connection>
 */
class ConnectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'plugin' => 'fake',
            'name' => fake()->unique()->company().' account',
            'config' => ['account' => 'test'],
            'secrets' => ['api_key' => 'sk-test'],
            'status' => Connection::STATUS_CONNECTED,
        ];
    }
}
