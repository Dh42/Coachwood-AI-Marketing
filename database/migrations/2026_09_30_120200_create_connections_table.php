<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connections', function (Blueprint $table) {
            $table->id();
            $table->string('plugin');
            // The brand it belongs to; none means shared by every brand.
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            // The name people see everywhere, such as "BioSteel Shopify".
            $table->string('name')->unique();
            // Non-secret details, such as the store domain.
            $table->json('config');
            // Tokens and keys. Encrypted, and never shown again after saving.
            $table->text('secrets');
            $table->string('status', 20);
            $table->timestamp('checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('plugin');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connections');
    }
};
