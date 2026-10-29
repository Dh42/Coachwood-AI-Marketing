<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The brand's catalogue, copied from its store (App\Catalogue).
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->nullable()->constrained()->nullOnDelete();
            // The store's own ID for it, such as a Shopify product GID.
            $table->string('external_id', 100);
            $table->string('handle', 255)->nullable();
            $table->string('title', 255);
            $table->string('type', 255)->nullable();
            $table->string('vendor', 255)->nullable();
            $table->string('status', 20)->default('active');
            // Its page on the online store; empty when it isn't on it.
            $table->string('url', 500)->nullable();
            $table->mediumText('description_html')->nullable();
            $table->json('tags');
            // Option names and their values, such as {"Size": ["1 kg", "2 kg"], "Flavour": [...]}.
            $table->json('options');
            // Text product details from the store (nutrition, benefits…), by a readable key.
            $table->json('details');
            $table->timestamp('synced_at')->nullable();
            // Gone from the store: kept so earlier requests still make sense.
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['brand_id', 'external_id']);
            $table->index(['brand_id', 'removed_at']);
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 100);
            $table->string('url', 1000);
            $table->string('alt', 500)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            // The background-removed copy on the files disk, once made.
            $table->string('cutout_path', 500)->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'external_id']);
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 100);
            $table->string('title', 255);
            $table->string('sku', 100)->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->boolean('available')->default(true);
            // Option values, such as {"Size": "1 kg", "Flavour": "Vanilla"}.
            $table->json('options');
            $table->unsignedSmallInteger('position')->default(0);
            $table->foreignId('product_image_id')->nullable()->constrained()->nullOnDelete();
            // Real packaging size, typed in by the team, so pack shots in one frame are scaled to each other.
            $table->unsignedInteger('width_mm')->nullable();
            $table->unsignedInteger('height_mm')->nullable();
            $table->unsignedInteger('depth_mm')->nullable();
            $table->foreignId('dimensions_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'external_id']);
        });

        Schema::create('catalogue_syncs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->nullable()->constrained()->nullOnDelete();
            // schedule or manual
            $table->string('source', 20);
            $table->string('status', 20);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('products')->default(0);
            $table->unsignedInteger('added')->default(0);
            $table->unsignedInteger('changed')->default(0);
            $table->unsignedInteger('removed')->default(0);
            $table->text('error')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['brand_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalogue_syncs');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('products');
    }
};
