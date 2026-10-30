<?php

namespace Coachwood\Plugins\Higgsfield;

use App\Plugins\PluginRegistry;
use App\Settings\SettingsAreas;
use Illuminate\Support\ServiceProvider;

class HiggsfieldServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(SettingsAreas::class)->addSections('ai', ['Higgsfield']);

        $this->app->make(PluginRegistry::class)->register($this->app->make(HiggsfieldPlugin::class));
    }
}
