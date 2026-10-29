<?php

namespace Coachwood\Plugins\Shopify;

use App\Plugins\PluginRegistry;
use App\Settings\SettingsAreas;
use Illuminate\Support\ServiceProvider;

class ShopifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // The API version is platform-wide: it shows under "For every brand" in the Store entry.
        $this->app->make(SettingsAreas::class)->addSections('store', ['Shopify']);

        $this->app->make(PluginRegistry::class)->register($this->app->make(ShopifyPlugin::class));
    }
}
