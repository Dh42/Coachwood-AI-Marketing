<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A request: the brief, what was asked and answered, the plan, and the estimate.
        Schema::create('requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            // planning, asking, planned, ready, cancelled, failed (running and done come with generation).
            $table->string('status', 20)->default('planning');
            $table->string('title')->nullable();
            $table->text('brief');
            $table->json('languages');
            // ad, image or both.
            $table->string('output', 10)->default('ad');
            // composed or generated (how a finished ad gets its text).
            $table->string('ad_style', 10)->default('composed');
            // none, captions, ad or all.
            $table->string('copy', 10)->default('all');
            $table->json('placement_ids');
            // How many images per shape; null means the brand's setting.
            $table->unsignedSmallInteger('per_ratio')->nullable();
            // "provider/model" the person chose; null lets the planner pick.
            $table->string('image_model', 150)->nullable();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('public')->default(true);
            $table->json('questions')->nullable();
            $table->json('answers')->nullable();
            $table->unsignedTinyInteger('rounds')->default(0);
            $table->json('plan')->nullable();
            // Estimated US dollars for the images, and the whole run.
            $table->decimal('image_cost', 10, 4)->nullable();
            $table->decimal('estimated_cost', 10, 4)->nullable();
            $table->text('error')->nullable();
            $table->timestamp('planned_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });

        // One row per placement in the plan: what's made for it and at what cost.
        Schema::create('request_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('placement_id')->constrained()->cascadeOnDelete();
            $table->string('ratio', 20);
            $table->string('output', 10);
            $table->string('image_model', 150);
            $table->unsignedSmallInteger('count');
            $table->decimal('estimated_cost', 10, 4)->default(0);
            $table->string('status', 20)->default('planned');
            $table->timestamps();

            $table->unique(['request_id', 'placement_id']);
        });

        // Reference images uploaded with a request: the look wanted (style) or a pack shot (product).
        Schema::create('request_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10)->default('style');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->timestamps();
        });

        // Every US dollar spent, as the providers report usage: by brand, person, request and kind of work.
        Schema::create('spend', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 30);
            // plan, image, copy, review, video…
            $table->string('kind', 20);
            $table->decimal('amount', 10, 5);
            $table->string('note')->nullable();
            $table->timestamp('created_at');

            $table->index(['user_id', 'created_at']);
            $table->index(['brand_id', 'created_at']);
            $table->index('created_at');
        });

        // A person's own monthly budget, when it differs from the default in settings.
        Schema::create('user_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('monthly_usd', 10, 2);
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_budgets');
        Schema::dropIfExists('spend');
        Schema::dropIfExists('request_references');
        Schema::dropIfExists('request_targets');
        Schema::dropIfExists('requests');
    }
};
