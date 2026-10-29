<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 32);
            // 0 = platform-wide; otherwise a brand id (no foreign key, so 0 is allowed).
            $table->unsignedBigInteger('brand_id')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'brand_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_assignments');
    }
};
