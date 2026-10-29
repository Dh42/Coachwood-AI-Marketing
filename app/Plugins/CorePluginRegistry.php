<?php

namespace App\Plugins;

/**
 * The core plugins installed, for the platform settings screen.
 */
class CorePluginRegistry
{
    /** @var array<string, CorePlugin> */
    private array $plugins = [];

    public function register(CorePlugin $plugin): void
    {
        $this->plugins[$plugin->key()] = $plugin;
    }

    /**
     * @return array<string, CorePlugin>
     */
    public function all(): array
    {
        return $this->plugins;
    }
}
