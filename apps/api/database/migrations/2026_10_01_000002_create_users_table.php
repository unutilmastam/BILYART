<?php

use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            // NULL only for SUPER_ADMIN (enforced by CHECK below).
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete();
            $table->string('role', 32);
            $table->string('name', 150);
            // Global login (phone or username), lowercase. Unique across the platform.
            $table->string('login', 64)->unique();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('failed_logins')->default(0);
            $table->dateTime('locked_until')->nullable();
            $table->dateTime('last_login_at')->nullable();
            $table->rememberToken();
            $table->datetimes();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'role']);
        });

        Constraints::check('users', 'users_role_chk', Constraints::in('role', ['SUPER_ADMIN', 'CLIENT_OWNER', 'CLIENT_MANAGER', 'CLIENT_OPERATOR']));
        Constraints::check(
            'users',
            'users_role_tenant_chk',
            sprintf("(%s = 'SUPER_ADMIN' AND %s IS NULL) OR (%s <> 'SUPER_ADMIN' AND %s IS NOT NULL)",
                Constraints::wrap('role'), Constraints::wrap('tenant_id'), Constraints::wrap('role'), Constraints::wrap('tenant_id'))
        );

        // Laravel web sessions; renamed so they never clash with billiard game sessions.
        Schema::create('web_sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_sessions');
        Schema::dropIfExists('users');
    }
};
