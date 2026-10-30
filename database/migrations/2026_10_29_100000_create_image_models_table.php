<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The image models the team can generate with: what each takes and
        // what it costs, as data, so a new model is a row and not a release.
        Schema::create('image_models', function (Blueprint $table) {
            $table->id();
            // The plugin that talks to it (gemini, openai…).
            $table->string('provider', 30);
            // The provider's own model ID.
            $table->string('model', 100);
            $table->string('label');
            $table->json('aspect_ratios');
            $table->json('sizes');
            // Provider quality names (OpenAI: low, medium, high); empty when the model has none.
            $table->json('qualities');
            $table->unsignedSmallInteger('max_references')->default(0);
            // Takes reference images and edits them.
            $table->boolean('edits')->default(false);
            $table->boolean('transparent')->default(false);
            $table->boolean('renders_text')->default(false);
            // Estimated US dollars per image, by size ("1K") or "quality size" ("medium 1K").
            $table->json('prices');
            // US dollars per million tokens, for the actual cost from the provider's usage figures.
            $table->decimal('input_price', 10, 4)->default(0);
            $table->decimal('image_input_price', 10, 4)->default(0);
            $table->decimal('output_price', 10, 4)->default(0);
            $table->text('notes')->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('position')->default(100);
            $table->timestamps();

            $table->unique(['provider', 'model']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_models');
    }
};
