<?php

namespace Database\Factories;

use App\Models\Brand;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Brand>
 */
class BrandFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'slug' => rtrim(mb_substr(Str::slug($name), 0, 33), '-').'-'.fake()->unique()->numberBetween(1, 99999),
            'name' => $name,
            'color' => fake()->hexColor(),
        ];
    }
}
