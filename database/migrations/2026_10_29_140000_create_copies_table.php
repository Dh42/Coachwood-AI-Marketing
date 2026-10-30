<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Copy written for a request: several versions per placement and language to its limits,
        // and the words set on finished ads (headline, offer, call to action) per language.
        Schema::create('copies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('placement_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('language', 5);
            // placement (copy for a placement) or ad (the words on finished ads)
            $table->string('kind', 10)->default('placement');
            $table->json('versions');
            $table->string('model', 100)->nullable();
            $table->decimal('cost', 10, 5)->default(0);
            // done or failed
            $table->string('status', 20)->default('done');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['request_id', 'placement_id', 'language', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('copies');
    }
};
