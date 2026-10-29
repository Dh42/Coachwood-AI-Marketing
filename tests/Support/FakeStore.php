<?php

namespace Tests\Support;

use App\Models\Connection;
use App\Plugins\Contracts\StoreProvider;

/**
 * A store with whatever products a test puts in it.
 */
class FakeStore extends FakePlugin implements StoreProvider
{
    /** @var list<array<string, mixed>> */
    public array $products = [];

    public ?\Throwable $failure = null;

    public function __construct(string $key = 'store')
    {
        parent::__construct($key);
    }

    public function catalogue(Connection $connection): iterable
    {
        if ($this->failure) {
            throw $this->failure;
        }

        return $this->products;
    }

    /**
     * @param  list<array{0: string, 1: array<string, string>}>  $variants  [id, options]
     * @return array<string, mixed>
     */
    public static function product(string $id, string $title, array $variants = [['v1', []]], array $extra = []): array
    {
        $options = [];
        foreach ($variants as [, $selected]) {
            foreach ($selected as $name => $value) {
                $options[$name] = array_values(array_unique([...($options[$name] ?? []), $value]));
            }
        }

        return [
            'external_id' => $id, 'handle' => str($title)->slug()->toString(), 'title' => $title, 'type' => 'Protein', 'vendor' => 'Test', 'status' => 'active',
            'url' => "https://shop.example/products/{$id}", 'description_html' => '<p>Hi</p>', 'tags' => ['tagged'],
            'options' => $options,
            'images' => [['external_id' => "{$id}-img", 'url' => "https://cdn.example/{$id}.png", 'alt' => null, 'position' => 0, 'width' => 800, 'height' => 800]],
            'variants' => array_map(fn ($v, $i) => ['external_id' => $v[0], 'title' => implode(' / ', $v[1]) ?: 'Default Title', 'sku' => strtoupper($v[0]), 'price' => '10.00', 'currency' => 'CAD', 'available' => true, 'options' => $v[1], 'position' => $i, 'image_external_id' => "{$id}-img"], $variants, array_keys($variants)),
            'details' => ['Benefits' => 'Good'],
            ...$extra,
        ];
    }
}
