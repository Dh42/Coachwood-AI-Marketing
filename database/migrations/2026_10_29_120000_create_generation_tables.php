<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One generation per shape in a run: the prompt as sent, the model, how many were asked for and how it went.
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained()->cascadeOnDelete();
            $table->string('ratio', 20);
            $table->string('image_model', 150);
            $table->text('prompt');
            $table->string('size', 10);
            $table->string('quality', 20)->nullable();
            $table->unsignedSmallInteger('count');
            $table->unsignedSmallInteger('done')->default(0);
            $table->unsignedSmallInteger('failed')->default(0);
            // queued, running, done, failed
            $table->string('status', 20)->default('queued');
            $table->decimal('cost', 10, 5)->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        // Each image that came back: the file, its size, what it cost. Review and composing add to it later.
        Schema::create('outputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generation_id')->constrained()->cascadeOnDelete();
            $table->string('ratio', 20);
            // image (a clean generation); ad, video and copy come with their steps.
            $table->string('kind', 10)->default('image');
            $table->unsignedSmallInteger('position');
            $table->string('path');
            $table->string('thumb_path')->nullable();
            $table->string('mime', 100);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('size');
            $table->string('served_by', 150)->nullable();
            $table->decimal('cost', 10, 5)->default(0);
            // new, and later shortlisted or rejected
            $table->string('status', 20)->default('new');
            $table->timestamps();

            $table->index(['request_id', 'ratio']);
        });

        Schema::table('requests', function (Blueprint $table) {
            $table->string('batch_id')->nullable()->after('confirmed_at');
            $table->decimal('actual_cost', 10, 5)->nullable()->after('estimated_cost');
            $table->timestamp('finished_at')->nullable()->after('batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('requests', fn (Blueprint $table) => $table->dropColumn(['batch_id', 'actual_cost', 'finished_at']));
        Schema::dropIfExists('outputs');
        Schema::dropIfExists('generations');
    }
};
