<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A model bots and graders can use, and what it costs.
 */
#[Fillable(['provider', 'model', 'label', 'input_price', 'output_price', 'cache_write_price', 'cache_read_price', 'thinking', 'refusal_fallback', 'enabled', 'position'])]
class LlmModel extends Model
{
    protected function casts(): array
    {
        return [
            'input_price' => 'float',
            'output_price' => 'float',
            'cache_write_price' => 'float',
            'cache_read_price' => 'float',
            'refusal_fallback' => 'boolean',
            'enabled' => 'boolean',
        ];
    }

    /**
     * @return array<string, string> model => label, for pickers.
     */
    public static function options(): array
    {
        return static::where('enabled', true)->orderBy('position')->pluck('label', 'model')->all();
    }

    /**
     * US dollars for this much usage.
     */
    public function cost(int $input, int $output, int $cacheWrite = 0, int $cacheRead = 0): float
    {
        return ($input * $this->input_price + $output * $this->output_price
            + $cacheWrite * $this->cache_write_price + $cacheRead * $this->cache_read_price) / 1_000_000;
    }
}
