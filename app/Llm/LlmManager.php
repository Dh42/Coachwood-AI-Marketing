<?php

namespace App\Llm;

use App\Models\Connection;
use App\Models\LlmModel;
use App\Plugins\Connections;
use App\Plugins\Contracts\LanguageProvider;
use App\Plugins\PluginRegistry;

/**
 * Sends a request to the right provider for its model and prices the
 * result. Providers are plugins (App\Plugins\Contracts\LanguageProvider),
 * each with a connection holding its key; a provider registered here by
 * hand takes precedence, which is how tests swap in a scripted model.
 */
class LlmManager
{
    /** @var array<string, LlmProvider|LanguageProvider> */
    private array $providers = [];

    public function __construct(private PluginRegistry $plugins, private Connections $connections) {}

    public function register(string $key, LlmProvider|LanguageProvider $provider): void
    {
        $this->providers[$key] = $provider;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $model = LlmModel::where('model', $request->model)->first()
            ?? throw new LlmException("The model \"{$request->model}\" isn't in the model list. Choose another in settings.");

        if (! $model->enabled) {
            throw new LlmException("The model \"{$model->label}\" is switched off on the Models page. Choose another in settings.");
        }

        $provider = $this->providers[$model->provider] ?? $this->plugins->providing(LanguageProvider::class)->get($model->provider)
            ?? throw new LlmException("The {$model->provider} plugin isn't installed or is switched off, so \"{$model->label}\" can't be used.");

        $response = $provider instanceof LanguageProvider
            ? $provider->complete($request, $model, $this->connection($model, $request->brandId))
            : $provider->complete($request, $model);

        return $response->withCost($model->cost($response->inputTokens, $response->outputTokens, $response->cacheWriteTokens, $response->cacheReadTokens));
    }

    private function connection(LlmModel $model, ?int $brandId): Connection
    {
        $name = $this->plugins->plugin($model->provider)?->name() ?? $model->provider;
        $connection = $this->connections->for($model->provider, $brandId);

        if (! $connection) {
            throw new LlmException("No {$name} connection. Add one under Connections with the API key.");
        }

        if (! $connection->isConnected()) {
            throw new LlmException("The {$name} connection \"{$connection->name}\" isn't working: ".($connection->last_error ?: 'it hasn\'t been checked yet').'. Open it under Connections and press "Check now".');
        }

        return $connection;
    }
}
