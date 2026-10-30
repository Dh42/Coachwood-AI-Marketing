<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The brand's voice and visual direction: one row per brand, created the first time its kit is opened.
        Schema::create('brand_kits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('voice');
            $table->json('direction');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Everything else in the kit: logos, colours, fonts, references, examples,
        // CTAs, legal lines, music and pack shots. Each kind keeps its own fields
        // in `data` (App\BrandKit\Kind); kinds with a file keep it on the app's disk.
        Schema::create('brand_kit_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->unsignedInteger('position')->default(0);
            $table->json('data');
            $table->string('path')->nullable();
            $table->string('thumb_path')->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['brand_id', 'kind', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_kit_items');
        Schema::dropIfExists('brand_kits');
    }
};
