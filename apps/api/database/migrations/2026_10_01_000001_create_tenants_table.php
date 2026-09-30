<?php

use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('name', 150);
            $table->string('contact_name', 150)->nullable();
            $table->string('contact_phone', 32)->nullable();
            // Administrative flag set by Super Admin. Subscription status (ACTIVE/EXPIRING_SOON/EXPIRED) is derived from expires_at.
            $table->string('status_flag', 16)->default('ACTIVE');
            $table->dateTime('subscription_expires_at')->nullable();
            $table->unsignedInteger('branch_limit')->default(1);
            $table->unsignedInteger('table_limit')->nullable();
            $table->unsignedInteger('device_limit')->nullable();
            $table->unsignedInteger('user_limit')->nullable();
            $table->string('timezone', 64)->default('Asia/Tashkent');
            $table->json('settings')->nullable();
            $table->datetimes();

            $table->index(['status_flag', 'subscription_expires_at']);
        });

        Constraints::check('tenants', 'tenants_status_flag_chk', Constraints::in('status_flag', ['ACTIVE', 'SUSPENDED', 'DEACTIVATED']));
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
