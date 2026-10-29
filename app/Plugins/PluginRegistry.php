<?php

namespace App\Plugins;

use App\Models\PluginState;
use App\Settings\SettingsRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Every installed integration plugin and whether it's switched on. A plugin
 * is a provider of something (images, video, language, a store, background
 * removal); the capability interfaces in App\Plugins\Contracts say which.
 */
class PluginRegistry
{
    /** @var array<string, IntegrationPlugin> */
    private array $plugins = [];

    /** @var array<string, bool>|null */
    private ?array $enabled = null;

    public function __construct(private SettingsRegistry $settings) {}

    public function register(IntegrationPlugin $plugin): void
    {
        $key = $plugin->key();

        if (isset($this->plugins[$key])) {
            throw new InvalidArgumentException("Plugin [{$key}] is already registered.");
        }

        $this->plugins[$key] = $plugin;

        foreach ($plugin->settings() as $setting) {
            $this->settings->define($setting);
        }
    }

    /**
     * @return Collection<string, IntegrationPlugin>
     */
    public function all(): Collection
    {
        return collect($this->plugins)->sortBy(fn (IntegrationPlugin $p) => $p->name());
    }

    /**
     * @return Collection<string, IntegrationPlugin>
     */
    public function enabled(): Collection
    {
        return $this->all()->filter(fn (IntegrationPlugin $p) => $this->isEnabled($p->key()));
    }

    /**
     * The enabled plugins that provide a capability (an interface in App\Plugins\Contracts).
     *
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @return Collection<string, T&IntegrationPlugin>
     */
    public function providing(string $contract): Collection
    {
        return $this->enabled()->filter(fn (IntegrationPlugin $p) => $p instanceof $contract);
    }

    public function plugin(string $key): ?IntegrationPlugin
    {
        return $this->plugins[$key] ?? null;
    }

    public function isEnabled(string $key): bool
    {
        $this->enabled ??= Schema::hasTable('plugin_states')
            ? PluginState::pluck('enabled', 'plugin')->map(fn ($v) => (bool) $v)->all()
            : [];

        // A newly installed plugin is on until someone switches it off.
        return isset($this->plugins[$key]) && ($this->enabled[$key] ?? true);
    }

    public function forgetState(): void
    {
        $this->enabled = null;
    }
}
