<?php

namespace App\Plugins\Contracts;

use App\Models\Connection;

/**
 * A plugin that reads a brand's product catalogue from its store (Shopify
 * first). The app keeps a copy (App\Catalogue) so the planner can search it
 * and pack shots are always to hand.
 */
interface StoreProvider
{
    /**
     * Every product in the store, one at a time, in this shape:
     *
     *   external_id, handle, title, type, vendor, status ('active'…), url (null when not on the online store),
     *   description_html, tags: list<string>, options: array<string, list<string>> (name => values),
     *   images: list<{external_id, url, alt, position, width, height}>,
     *   variants: list<{external_id, title, sku, price, currency, available, options: array<string, string>, image_external_id}>,
     *   details: array<string, string> (text product details, such as nutrition or benefits, by a readable key)
     *
     * @return iterable<array<string, mixed>>
     */
    public function catalogue(Connection $connection): iterable;
}
