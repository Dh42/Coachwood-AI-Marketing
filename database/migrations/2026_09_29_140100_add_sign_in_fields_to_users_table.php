<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Google users never have a password; invited outside users set one.
            $table->string('password')->nullable()->change();
            $table->string('google_id')->nullable()->unique()->after('email');
            $table->timestamp('last_login_at')->nullable()->after('remember_token');
            $table->timestamp('disabled_at')->nullable()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['google_id', 'last_login_at', 'disabled_at']);
            $table->string('password')->nullable(false)->change();
        });
    }
};
