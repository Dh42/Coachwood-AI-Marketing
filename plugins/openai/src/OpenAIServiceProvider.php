<?php

namespace Coachwood\Plugins\OpenAI;

use App\Plugins\PluginRegistry;
use App\Settings\SettingsAreas;
use Illuminate\Support\ServiceProvider;

class OpenAIServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(SettingsAreas::class)->addSections('ai', ['OpenAI']);

        $this->app->make(PluginRegistry::class)->register($this->app->make(OpenAIPlugin::class));
    }
}
