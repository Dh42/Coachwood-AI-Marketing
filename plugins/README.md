# Plugins

Each provider or integration is a self-contained Composer package here (`plugins/<name>`), installed through the path repository in the root `composer.json`: its own service provider, migrations, views, routes and tests. Adding one never needs a change to core code.

A plugin registers itself with `App\Plugins\PluginRegistry` and says what it can do by implementing the capability interfaces in `App\Plugins\Contracts`:

- `StoreProvider`: a brand's product catalogue (`shopify`).
- `ImageProvider`: images from a prompt and reference images (`gemini`, `openai`). The plugin provides its starting rows for `image_models` and the call; `App\Images\ImageGenerator` checks a request against the model row and finds the connection before calling it.
- `LanguageProvider`: the models that plan, write and review (`anthropic`); `App\Llm\LlmManager` finds the plugin by the model's provider in `llm_models`.
- `BackgroundRemover`: cuts the product out of a store photo, leaving a see-through background (`photoroom`). `App\Catalogue\Cutouts` makes one cut-out per photo an ad would use and keeps it on the disk; the composer and the reviewer use it when the brand kit has no transparent pack shot for the variant.
- Video comes with its step.

Each account of a provider is a `Connection` with the plugin's fields (an API key, a store address) stored encrypted; the plugin's `healthCheck()` runs when one is saved and on "Check now". A plugin can add settings (`settings()`), which show in the settings area whose sections it registers with `SettingsAreas::addSections()`.
