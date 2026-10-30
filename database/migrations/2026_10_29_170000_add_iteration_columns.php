<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A generation asked for after the run: a change on one image (with the words typed and how the planner turned them into an instruction) or a shape run again.
        Schema::table('generations', function (Blueprint $table) {
            $table->foreignId('source_output_id')->nullable()->after('request_id')->constrained('outputs')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->after('source_output_id')->constrained()->nullOnDelete();
            $table->text('instruction')->nullable()->after('prompt');
            $table->json('change')->nullable()->after('instruction');
        });

        // A changed version of an image points at the image the change was asked on.
        Schema::table('outputs', function (Blueprint $table) {
            $table->foreignId('source_id')->nullable()->after('parent_id')->constrained('outputs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('outputs', fn (Blueprint $table) => $table->dropConstrainedForeignId('source_id'));
        Schema::table('generations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_output_id');
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['instruction', 'change']);
        });
    }
};
