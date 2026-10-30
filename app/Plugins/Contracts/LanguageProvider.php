<?php

namespace App\Plugins\Contracts;

use App\Llm\LlmException;
use App\Llm\LlmRequest;
use App\Llm\LlmResponse;
use App\Models\Connection;
use App\Models\LlmModel;

/**
 * A plugin whose models plan, write and review (Anthropic first). Its
 * models are rows in llm_models naming it as their provider; the key comes
 * from the connection the brand may use.
 */
interface LanguageProvider
{
    /**
     * @throws LlmException
     */
    public function complete(LlmRequest $request, LlmModel $model, Connection $connection): LlmResponse;
}
