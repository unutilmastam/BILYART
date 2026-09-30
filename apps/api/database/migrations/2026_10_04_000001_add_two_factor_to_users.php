<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Optional TOTP two-factor login (SECURITY.md §1a). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable(); // Crypt (APP_KEY); pending until confirmed
            $table->dateTime('two_factor_confirmed_at')->nullable();
            $table->unsignedBigInteger('two_factor_last_step')->nullable(); // replay protection
            $table->text('two_factor_recovery_codes')->nullable(); // JSON list of sha256 hashes
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_confirmed_at', 'two_factor_last_step', 'two_factor_recovery_codes']);
        });
    }
};
