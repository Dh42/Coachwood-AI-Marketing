<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Installed plugins are found in code; this records which are switched on.
        Schema::create('plugin_states', function (Blueprint $table) {
            $table->id();
            $table->string('plugin')->unique();
            $table->boolean('enabled')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_states');
    }
};
