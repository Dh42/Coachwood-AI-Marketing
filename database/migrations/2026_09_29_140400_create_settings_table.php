<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            $table->string('scope', 16);
            // 0 for platform settings; otherwise the brand or user id.
            $table->unsignedBigInteger('scope_id')->default(0);
            $table->json('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['key', 'scope', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
