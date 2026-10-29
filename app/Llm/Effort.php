<?php

namespace App\Llm;

/**
 * How much the model thinks before answering. Set for the group, and
 * optionally per bot.
 */
final class Effort
{
    public const OPTIONS = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'xhigh' => 'Extra high', 'max' => 'Maximum'];
}
