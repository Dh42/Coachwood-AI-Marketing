<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When a rejected output's files were deleted by the nightly clean-up; the row and its reason stay.
        Schema::table('outputs', function (Blueprint $table) {
            $table->timestamp('removed_at')->nullable()->after('reason');
            $table->index(['status', 'removed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('outputs', function (Blueprint $table) {
            $table->dropIndex(['status', 'removed_at']);
            $table->dropColumn('removed_at');
        });
    }
};
