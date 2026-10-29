<?php

namespace App\Plugins;

/**
 * A plugin that adds a feature to the platform itself, rather than a
 * connection to a service (see docs/plugins.md, "Core plugins").
 *
 * The plugin's service provider registers it in CorePluginRegistry and wires
 * up whichever extension points it needs: Navigation, Permissions,
 * SettingsRegistry, Criteria, AlertChecks, HistoryExtensions, ReportWidgets,
 * platform events (App\Events), and the scheduler.
 */
interface CorePlugin
{
    public function key(): string;

    public function name(): string;

    public function description(): string;
}
