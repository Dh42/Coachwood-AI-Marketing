<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $title = fake()->unique()->words(3, true);

        return [
            'brand_id' => Brand::factory(),
            'external_id' => 'gid://shopify/Product/'.fake()->unique()->numberBetween(1, 999999),
            'handle' => Str::slug($title),
            'title' => Str::title($title),
            'type' => 'Protein',
            'vendor' => 'Test',
            'status' => 'active',
            'url' => 'https://example.com/products/'.Str::slug($title),
            'description_html' => '<p>A product.</p>',
            'tags' => [],
            'options' => [],
            'details' => [],
            'synced_at' => now(),
        ];
    }
}
