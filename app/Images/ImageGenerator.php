<?php

namespace App\Images;

use App\Models\Brand;
use App\Models\Connection;
use App\Models\ImageModel;
use App\Plugins\Connections;
use App\Plugins\Contracts\ImageProvider;
use App\Plugins\PluginRegistry;
use Illuminate\Validation\ValidationException;

/**
 * Sends a request to the plugin behind a model, with the connection the
 * brand may use, after checking the model takes it. The generation jobs
 * call this; nothing else talks to the image plugins.
 */
class ImageGenerator
{
    public function __construct(private PluginRegistry $plugins, private Connections $connections, private ImageModels $models) {}

    /**
     * @throws ImageException When the plugin, the connection or the provider fails.
     * @throws ValidationException When the model doesn't take the request.
     */
    public function generate(ImageModel $model, ImageRequest $request, ?Brand $brand = null): ImageResult
    {
        $plugin = $this->plugin($model);
        $connection = $this->connection($model, $brand);
        $ratio = $this->models->check($model, $request);

        $result = $plugin->generateImages($connection, $model, $request->with(aspectRatio: $ratio));

        return $result->withAspectRatio($ratio);
    }

    /**
     * Estimated US dollars for a request, before anything is spent.
     */
    public function estimate(ImageModel $model, ImageRequest $request, ?Brand $brand = null): ?float
    {
        $plugin = $this->plugins->plugin($model->provider);
        $quality = $request->quality ?? ($plugin instanceof ImageProvider ? $plugin->defaultQuality($model, $brand) : null);

        return $model->estimate($request->with(quality: $quality));
    }

    /**
     * Whether a brand could generate with a model right now, or why not.
     */
    public function problem(ImageModel $model, ?Brand $brand = null): ?string
    {
        try {
            $this->plugin($model);
            $this->connection($model, $brand);
        } catch (ImageException $e) {
            return $e->getMessage();
        }

        return null;
    }

    private function plugin(ImageModel $model): ImageProvider
    {
        $plugin = $this->plugins->providing(ImageProvider::class)->get($model->provider);

        if (! $plugin) {
            throw new ImageException("The {$model->provider} plugin isn't installed or is switched off, so {$model->label} can't be used.");
        }

        if (! $model->enabled) {
            throw new ImageException("{$model->label} is switched off on the Models page.");
        }

        return $plugin;
    }

    private function connection(ImageModel $model, ?Brand $brand): Connection
    {
        $name = $this->plugins->plugin($model->provider)?->name() ?? $model->provider;
        $connection = $this->connections->for($model->provider, $brand);

        if (! $connection) {
            throw new ImageException("No {$name} connection".($brand ? " for {$brand->name}" : '').'. Add one under Connections.');
        }

        if (! $connection->isConnected()) {
            throw new ImageException("The {$name} connection \"{$connection->name}\" isn't working: ".($connection->last_error ?: 'it hasn\'t been checked yet').'. Open it under Connections and press "Check now".');
        }

        return $connection;
    }
}
