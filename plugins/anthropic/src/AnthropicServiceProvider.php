<?php

namespace Coachwood\Plugins\Anthropic;

use App\Plugins\PluginRegistry;
use Illuminate\Support\ServiceProvider;

class AnthropicServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(PluginRegistry::class)->register($this->app->make(AnthropicPlugin::class));
    }
}
