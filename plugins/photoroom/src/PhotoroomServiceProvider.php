<?php

namespace Coachwood\Plugins\Photoroom;

use App\Plugins\PluginRegistry;
use App\Settings\SettingsAreas;
use Illuminate\Support\ServiceProvider;

class PhotoroomServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // The price and size are platform-wide: they show under "For every brand" in the Store entry, beside the cut-out setting.
        $this->app->make(SettingsAreas::class)->addSections('store', ['Photoroom']);

        $this->app->make(PluginRegistry::class)->register($this->app->make(PhotoroomPlugin::class));
    }
}
