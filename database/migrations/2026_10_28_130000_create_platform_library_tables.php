<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The platforms creative is made for (Instagram, TikTok…): editable rows, never code.
        Schema::create('platforms', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('name', 80);
            $table->text('prompt_note')->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // One row per placement: its pixel size, aspect ratio, safe zone, file
        // rules, video length range and copy limits.
        Schema::create('placements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40);
            $table->string('name', 80);
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->string('ratio', 12);
            $table->json('formats');
            $table->unsignedSmallInteger('safe_top')->default(0);
            $table->unsignedSmallInteger('safe_bottom')->default(0);
            $table->unsignedSmallInteger('safe_left')->default(0);
            $table->unsignedSmallInteger('safe_right')->default(0);
            $table->json('image_types');
            $table->unsignedInteger('max_image_kb')->nullable();
            $table->json('video_types');
            $table->unsignedInteger('max_video_mb')->nullable();
            $table->unsignedSmallInteger('video_min_seconds')->nullable();
            $table->unsignedSmallInteger('video_max_seconds')->nullable();
            $table->json('copy_limits');
            $table->text('notes')->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['platform_id', 'key']);
        });

        // Sets of placements the team ticks as one ("Meta full set").
        Schema::create('placement_presets', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->json('placement_ids');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('placement_presets');
        Schema::dropIfExists('placements');
        Schema::dropIfExists('platforms');
    }
};
