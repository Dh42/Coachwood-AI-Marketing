<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A brand's layouts: where the words, logo, product and button sit on a finished ad, per shape.
        Schema::create('layout_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('ratio', 20);
            $table->string('name', 100);
            $table->boolean('is_default')->default(false);
            $table->json('settings');
            $table->unsignedInteger('position')->default(100);
            $table->timestamps();

            $table->index(['brand_id', 'ratio']);
        });

        Schema::table('outputs', function (Blueprint $table) {
            // A finished ad: the clean image it was made from, the placement it's sized for, and how the checks went.
            $table->foreignId('parent_id')->nullable()->after('generation_id')->constrained('outputs')->cascadeOnDelete();
            $table->foreignId('placement_id')->nullable()->after('parent_id')->constrained()->nullOnDelete();
            $table->foreignId('layout_template_id')->nullable()->after('placement_id')->constrained()->nullOnDelete();
            $table->string('language', 5)->nullable()->after('kind');
            $table->json('checks')->nullable()->after('status');
        });

        Schema::table('generations', function (Blueprint $table) {
            $table->text('compose_error')->nullable()->after('error');
        });
    }

    public function down(): void
    {
        Schema::table('generations', fn (Blueprint $table) => $table->dropColumn('compose_error'));
        Schema::table('outputs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropConstrainedForeignId('placement_id');
            $table->dropConstrainedForeignId('layout_template_id');
            $table->dropColumn(['language', 'checks']);
        });
        Schema::dropIfExists('layout_templates');
    }
};
