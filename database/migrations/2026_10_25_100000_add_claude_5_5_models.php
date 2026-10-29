<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const MODELS = [
        // Thinking is always on for Opus 5.5.
        ['model' => 'claude-opus-5-5', 'label' => 'Claude Opus 5.5', 'input_price' => 4, 'output_price' => 20, 'cache_write_price' => 5, 'cache_read_price' => 0.2, 'position' => 5],
        ['model' => 'claude-sonnet-5-5', 'label' => 'Claude Sonnet 5.5', 'input_price' => 2, 'output_price' => 10, 'cache_write_price' => 2.5, 'cache_read_price' => 0.1, 'position' => 15],
    ];

    public function up(): void
    {
        // Anthropic list prices, October 2026 (5-minute cache writes). The refusal
        // fallback stays off until Anthropic lists it for these models.
        foreach (self::MODELS as $model) {
            DB::table('llm_models')->updateOrInsert(['model' => $model['model']], [
                ...$model, 'provider' => 'anthropic', 'thinking' => 'adaptive', 'refusal_fallback' => false, 'enabled' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('llm_models')->whereIn('model', array_column(self::MODELS, 'model'))->delete();
    }
};
