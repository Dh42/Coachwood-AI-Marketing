<?php

namespace Coachwood\Plugins\Shopify;

use App\Models\Connection;
use App\Settings\Settings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * Talks to one store's Admin GraphQL API.
 */
class ShopifyClient
{
    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed> The "data" part of the response.
     */
    public function query(Connection $connection, string $query, array $variables = []): array
    {
        $domain = $connection->config('store_domain');
        // Looked up each time: this client lives as long as the worker, settings don't.
        $version = app(Settings::class)->get('shopify.api_version');

        $send = fn () => Http::withHeaders(['X-Shopify-Access-Token' => (string) $connection->secret('access_token')])
            ->acceptJson()
            ->timeout(30)
            // No variables means no "variables" at all: an empty one goes out as [], which Shopify refuses.
            ->post("https://{$domain}/admin/api/{$version}/graphql.json", $variables === [] ? ['query' => $query] : ['query' => $query, 'variables' => $variables]);

        $response = $send();

        // Throttled: Shopify refills its budget each second, so wait for enough and try again.
        for ($try = 0; $try < 3 && collect($response->json('errors') ?? [])->contains(fn ($e) => ($e['extensions']['code'] ?? null) === 'THROTTLED'); $try++) {
            $cost = $response->json('extensions.cost');
            $needed = max(0, ($cost['requestedQueryCost'] ?? 100) - ($cost['throttleStatus']['currentlyAvailable'] ?? 0));
            Sleep::for((int) ceil($needed / max(1, $cost['throttleStatus']['restoreRate'] ?? 50)) + 1)->seconds();
            $response = $send();
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new RuntimeException('Shopify refused the access token. Check it on the connection, and that the custom app has the read_products permission.');
        }

        if ($response->failed()) {
            throw new RuntimeException("Shopify returned an error ({$response->status()}).");
        }

        if ($errors = $response->json('errors')) {
            throw new RuntimeException('Shopify: '.collect($errors)->pluck('message')->implode('; '));
        }

        return $response->json('data') ?? [];
    }
}
