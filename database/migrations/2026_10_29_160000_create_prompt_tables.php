<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One prompt per brand and type (planner, image, copy, review_image, review_ad),
        // and one per person and type for their own additions (user_id set).
        Schema::create('prompts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('current_revision_id')->nullable();
            $table->timestamps();
            $table->unique(['brand_id', 'type', 'user_id']);
        });

        // Every save: the text, the review criteria and hard fails for review
        // prompts, who made it, how (starter, hand, ai, rollback) and from what.
        Schema::create('prompt_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prompt_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->text('body');
            $table->json('settings')->nullable();
            $table->string('note', 200)->nullable();
            $table->string('method', 20);
            $table->text('request')->nullable();
            $table->unsignedBigInteger('restored_from')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['prompt_id', 'number']);
        });

        // Which revisions each piece of work used, so a bad batch can be traced.
        foreach (['requests', 'generations', 'copies', 'reviews'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('prompt_revision_id')->nullable()->constrained('prompt_revisions')->nullOnDelete();
                $table->foreignId('addition_revision_id')->nullable()->constrained('prompt_revisions')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['requests', 'generations', 'copies', 'reviews'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('prompt_revision_id');
                $table->dropConstrainedForeignId('addition_revision_id');
            });
        }
        Schema::dropIfExists('prompt_revisions');
        Schema::dropIfExists('prompts');
    }
};
