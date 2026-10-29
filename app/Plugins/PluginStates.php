<?php

namespace App\Plugins;

use App\Models\PluginState;
use App\Models\User;

/**
 * Switching installed plugins on and off. Switching one off hides its
 * connections' actions from every bot at once; nothing is deleted.
 */
class PluginStates
{
    public function __construct(private PluginRegistry $plugins) {}

    public function set(string $key, bool $enabled, User $by): void
    {
        $plugin = $this->plugins->plugin($key) ?? abort(404);

        PluginState::updateOrCreate(['plugin' => $key], ['enabled' => $enabled, 'updated_by' => $by->id]);
        $this->plugins->forgetState();

        activity('connections')
            ->causedBy($by)
            ->event($enabled ? 'plugin_enabled' : 'plugin_disabled')
            ->withProperties(['plugin' => $key])
            ->log(($enabled ? 'Switched on' : 'Switched off')." the {$plugin->name()} plugin");
    }
}
