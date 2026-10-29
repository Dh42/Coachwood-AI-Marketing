<?php

namespace App\Llm;

use App\Models\LlmModel;

/**
 * One AI provider (Anthropic first). Add another by implementing this and
 * registering it in LlmManager; models in llm_models name their provider.
 */
interface LlmProvider
{
    /**
     * @throws LlmException
     */
    public function complete(LlmRequest $request, LlmModel $model): LlmResponse;
}
