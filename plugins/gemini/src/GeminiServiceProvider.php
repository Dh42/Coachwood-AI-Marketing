<?php

namespace Coachwood\Plugins\Gemini;

use App\Plugins\PluginRegistry;
use App\Settings\SettingsAreas;
use Illuminate\Support\ServiceProvider;

class GeminiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // The API version is platform-wide: it shows under "For every brand" in the AI models entry.
        $this->app->make(SettingsAreas::class)->addSections('ai', ['Gemini']);

        $this->app->make(PluginRegistry::class)->register($this->app->make(GeminiPlugin::class));
    }
}
