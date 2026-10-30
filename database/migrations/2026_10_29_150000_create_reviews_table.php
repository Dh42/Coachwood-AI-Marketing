<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The reviewer's scores for one output: per criterion, with the weighted overall, hard fails and a one-line reason.
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('output_id')->constrained()->cascadeOnDelete();
            $table->string('model', 100)->nullable();
            $table->json('scores');
            $table->unsignedTinyInteger('overall')->nullable();
            $table->json('hard_fails');
            $table->string('reason', 300)->nullable();
            $table->decimal('cost', 10, 5)->default(0);
            // done or failed
            $table->string('status', 20)->default('done');
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::table('outputs', function (Blueprint $table) {
            $table->unsignedTinyInteger('score')->nullable()->after('status');
            $table->string('reason', 300)->nullable()->after('score');
        });

        Schema::table('requests', function (Blueprint $table) {
            // How many to deliver per shape or placement; null means the brand's setting.
            $table->unsignedSmallInteger('deliver')->nullable()->after('per_ratio');
        });
    }

    public function down(): void
    {
        Schema::table('requests', fn (Blueprint $table) => $table->dropColumn('deliver'));
        Schema::table('outputs', fn (Blueprint $table) => $table->dropColumn(['score', 'reason']));
        Schema::dropIfExists('reviews');
    }
};
