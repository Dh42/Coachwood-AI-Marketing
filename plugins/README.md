# Plugins

Each provider or integration is a self-contained Composer package here (`plugins/<name>`), installed through the path repository in the root `composer.json`: its own service provider, migrations, views, routes and tests. Adding one never needs a change to core code.

A plugin registers itself with `App\Plugins\PluginRegistry` and says what it can do by implementing the capability interfaces in `App\Plugins\Contracts` (image, video, language, store, background removal).
