<?php

namespace App\Llm;

use App\Models\LlmModel;
use InvalidArgumentException;

/**
 * Sends a request to the right provider for its model and prices the result.
 */
class LlmManager
{
    /** @var array<string, LlmProvider> */
    private array $providers = [];

    public function register(string $key, LlmProvider $provider): void
    {
        $this->providers[$key] = $provider;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $model = LlmModel::where('model', $request->model)->first()
            ?? throw new LlmException("The model \"{$request->model}\" isn't in the model list. Choose another in settings.");

        $provider = $this->providers[$model->provider]
            ?? throw new InvalidArgumentException("No provider is registered for \"{$model->provider}\".");

        $response = $provider->complete($request, $model);

        return $response->withCost($model->cost($response->inputTokens, $response->outputTokens, $response->cacheWriteTokens, $response->cacheReadTokens));
    }
}
