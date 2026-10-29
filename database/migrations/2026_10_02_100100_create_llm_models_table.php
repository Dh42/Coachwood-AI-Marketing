<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The models bots and graders can use, with prices as data so cost
        // reporting works for any provider. Prices are US dollars per million tokens.
        Schema::create('llm_models', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30);
            $table->string('model', 100)->unique();
            $table->string('label');
            $table->decimal('input_price', 10, 4);
            $table->decimal('output_price', 10, 4);
            $table->decimal('cache_write_price', 10, 4);
            $table->decimal('cache_read_price', 10, 4);
            // adaptive, budget (older models) or none
            $table->string('thinking', 20)->default('adaptive');
            // Supports Anthropic's server-side refusal fallback.
            $table->boolean('refusal_fallback')->default(false);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('position')->default(100);
            $table->timestamps();
        });

        // Anthropic list prices, June 2026. Cache writes are 1.25x input (5-minute cache),
        // cache reads 0.1x input, except where Anthropic prices them separately.
        $now = now();
        DB::table('llm_models')->insert(array_map(fn ($row) => [...$row, 'provider' => 'anthropic', 'created_at' => $now, 'updated_at' => $now], [
            ['model' => 'claude-opus-5', 'label' => 'Claude Opus 5', 'input_price' => 5, 'output_price' => 25, 'cache_write_price' => 6.25, 'cache_read_price' => 0.5, 'thinking' => 'adaptive', 'refusal_fallback' => true, 'position' => 10],
            ['model' => 'claude-sonnet-5', 'label' => 'Claude Sonnet 5', 'input_price' => 2, 'output_price' => 10, 'cache_write_price' => 2.5, 'cache_read_price' => 0.2, 'thinking' => 'adaptive', 'refusal_fallback' => false, 'position' => 20],
            ['model' => 'claude-haiku-4-5', 'label' => 'Claude Haiku 4.5', 'input_price' => 1, 'output_price' => 5, 'cache_write_price' => 1.25, 'cache_read_price' => 0.1, 'thinking' => 'none', 'refusal_fallback' => false, 'position' => 30],
            ['model' => 'claude-fable-5-1', 'label' => 'Claude Fable 5.1', 'input_price' => 10, 'output_price' => 50, 'cache_write_price' => 12.5, 'cache_read_price' => 0.25, 'thinking' => 'adaptive', 'refusal_fallback' => true, 'position' => 40],
            ['model' => 'claude-opus-4-8', 'label' => 'Claude Opus 4.8', 'input_price' => 5, 'output_price' => 25, 'cache_write_price' => 6.25, 'cache_read_price' => 0.5, 'thinking' => 'adaptive', 'refusal_fallback' => false, 'position' => 50],
            ['model' => 'claude-sonnet-4-6', 'label' => 'Claude Sonnet 4.6', 'input_price' => 3, 'output_price' => 15, 'cache_write_price' => 3.75, 'cache_read_price' => 0.3, 'thinking' => 'adaptive', 'refusal_fallback' => false, 'position' => 60],
        ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_models');
    }
};
