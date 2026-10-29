<?php

namespace Coachwood\Plugins\Shopify;

use App\Models\Connection;
use App\Plugins\ConnectionField;
use App\Plugins\ConnectionMethod;
use App\Plugins\Contracts\StoreProvider;
use App\Plugins\HealthResult;
use App\Plugins\IntegrationPlugin;
use App\Settings\SettingControl;
use App\Settings\SettingDefinition;
use Generator;
use Illuminate\Support\Str;

/**
 * A brand's Shopify store, read-only: products, variants (size and
 * flavour), images and text product details, for the catalogue.
 */
class ShopifyPlugin extends IntegrationPlugin implements StoreProvider
{
    /** Product detail types worth keeping ("string" is Shopify's older text type); numbers, references and the like aren't. */
    private const TEXT_TYPES = ['string', 'single_line_text_field', 'multi_line_text_field', 'rich_text_field', 'list.single_line_text_field'];

    public function __construct(private ShopifyClient $client) {}

    public function key(): string
    {
        return 'shopify';
    }

    public function name(): string
    {
        return 'Shopify';
    }

    public function description(): string
    {
        return 'A brand\'s store: its products, sizes and flavours, photos and product details, read each night and on demand.';
    }

    public function connectionMethod(): ConnectionMethod
    {
        return ConnectionMethod::Fields;
    }

    public function connectionFields(): array
    {
        return [
            new ConnectionField('store_domain', 'Store address',
                help: 'The store\'s myshopify.com address.',
                placeholder: 'your-store.myshopify.com',
                rules: ['regex:/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/i']),
            new ConnectionField('access_token', 'Admin API access token',
                help: 'From a custom app made for this app in Shopify (Settings → Apps and sales channels → Develop apps) with only the read_products permission. Nothing else is needed.',
                secret: true,
                placeholder: 'shpat_…'),
        ];
    }

    public function settings(): array
    {
        return [new SettingDefinition(
            key: 'shopify.api_version',
            label: 'Shopify API version',
            description: 'The Shopify Admin API version every Shopify connection uses. Change it when Shopify retires the current one.',
            control: SettingControl::Select,
            default: '2026-07',
            rules: ['value' => ['required', 'regex:/^\d{4}-(01|04|07|10)$/']],
            section: 'Shopify',
            options: collect(['2026-01', '2026-04', '2026-07', '2026-10', '2027-01'])->mapWithKeys(fn ($v) => [$v => $v])->all(),
            advanced: true,
        )];
    }

    public function accountLabel(Connection $connection): string
    {
        return (string) $connection->config('store_domain');
    }

    public function healthCheck(Connection $connection): HealthResult
    {
        $shop = $this->client->query($connection, '{ shop { name myshopifyDomain } }')['shop'] ?? null;

        return $shop ? HealthResult::ok("Connected to {$shop['name']}") : HealthResult::failed('Shopify didn\'t return the store details.');
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function catalogue(Connection $connection): Generator
    {
        $after = null;

        do {
            $data = $this->client->query($connection,
                'query($after: String) { products(first: 25, after: $after, sortKey: TITLE) {
                    pageInfo { hasNextPage endCursor }
                    nodes { id handle title productType vendor status tags onlineStoreUrl descriptionHtml
                        options { name values }
                        images(first: 50) { nodes { id url altText width height } }
                        variants(first: 100) { nodes { id title sku price availableForSale position selectedOptions { name value } image { id } } }
                        metafields(first: 100) { nodes { namespace key type value } } } } }',
                array_filter(['after' => $after]),
            )['products'];

            foreach ($data['nodes'] as $p) {
                yield $this->product($p);
            }

            $after = $data['pageInfo']['hasNextPage'] ? $data['pageInfo']['endCursor'] : null;
        } while ($after);
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function product(array $p): array
    {
        $options = [];
        foreach ($p['options'] ?? [] as $option) {
            if (count($option['values']) > 1 || $option['values'] !== ['Default Title']) {
                $options[$option['name']] = array_values($option['values']);
            }
        }

        $images = [];
        foreach ($p['images']['nodes'] ?? [] as $i => $image) {
            $images[] = ['external_id' => $image['id'], 'url' => $image['url'], 'alt' => $image['altText'] ?? null, 'position' => $i, 'width' => $image['width'] ?? null, 'height' => $image['height'] ?? null];
        }

        $variants = [];
        foreach ($p['variants']['nodes'] ?? [] as $i => $v) {
            $selected = collect($v['selectedOptions'] ?? [])->filter(fn ($o) => $o['value'] !== 'Default Title')->mapWithKeys(fn ($o) => [$o['name'] => $o['value']])->all();
            $variants[] = [
                'external_id' => $v['id'], 'title' => $v['title'], 'sku' => $v['sku'] ?: null, 'price' => $v['price'] ?? null, 'currency' => null,
                'available' => (bool) ($v['availableForSale'] ?? true), 'options' => $selected, 'position' => $v['position'] ?? $i,
                'image_external_id' => $v['image']['id'] ?? null,
            ];
        }

        $details = [];
        foreach ($p['metafields']['nodes'] ?? [] as $m) {
            if (in_array($m['type'], self::TEXT_TYPES, true) && filled($m['value'])) {
                $details[self::label($m['key'])] = self::text($m['type'], $m['value']);
            }
        }

        return [
            'external_id' => $p['id'], 'handle' => $p['handle'], 'title' => $p['title'], 'type' => $p['productType'] ?: null, 'vendor' => $p['vendor'] ?: null,
            'status' => strtolower((string) ($p['status'] ?? 'active')), 'url' => $p['onlineStoreUrl'] ?: null, 'description_html' => $p['descriptionHtml'] ?: null,
            'tags' => array_values($p['tags'] ?? []), 'options' => $options, 'images' => $images, 'variants' => $variants, 'details' => $details,
        ];
    }

    /**
     * A detail's key as words: "[p][_t] 2b. nutritional_fact" becomes "Nutritional fact".
     */
    private static function label(string $key): string
    {
        $key = preg_replace('/^(\[[^\]]*\]\s*)+/', '', $key);
        $key = preg_replace('/^\s*\d+[a-z]?[.)]\s*/i', '', $key);

        return Str::ucfirst(trim(str_replace(['_', '-'], ' ', $key)));
    }

    /**
     * A detail's value as plain text: rich text and lists flattened, HTML stripped.
     */
    private static function text(string $type, string $value): string
    {
        if ($type === 'rich_text_field') {
            $value = self::richText(json_decode($value, true) ?? []);
        } elseif ($type === 'list.single_line_text_field') {
            $value = implode("\n", array_map('strval', json_decode($value, true) ?? []));
        }

        return trim(html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/li|\/h\d)[^>]*>/i', "\n", $value))));
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function richText(array $node): string
    {
        $inner = collect($node['children'] ?? [])->map(fn ($child) => self::richText($child))->implode('');

        return match ($node['type'] ?? '') {
            'text' => (string) ($node['value'] ?? ''),
            'paragraph', 'heading' => "{$inner}\n",
            'list-item' => "- {$inner}\n",
            default => $inner,
        };
    }
}
