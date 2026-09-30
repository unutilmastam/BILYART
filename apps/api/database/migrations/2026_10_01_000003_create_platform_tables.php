<?php

use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('amount'); // UZS
            $table->char('currency', 3)->default('UZS');
            $table->string('method', 16);
            $table->string('note', 500)->nullable();
            $table->dateTime('paid_at');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->datetimes();

            $table->index(['tenant_id', 'paid_at']);
        });
        Constraints::check('subscription_payments', 'sub_payments_method_chk', Constraints::in('method', ['CASH', 'BANK_TRANSFER', 'CARD_TRANSFER', 'OTHER']));
        Constraints::check('subscription_payments', 'sub_payments_currency_chk', Constraints::in('currency', ['UZS']));

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('expires_at');
            $table->unsignedInteger('days');
            $table->string('source', 16);
            $table->foreignId('payment_id')->nullable()->unique()->constrained('subscription_payments')->restrictOnDelete();
            $table->string('reason', 500)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->datetimes();

            $table->index(['tenant_id', 'expires_at']);
        });
        Constraints::check('subscriptions', 'subscriptions_source_chk', Constraints::in('source', ['PAYMENT', 'MANUAL_ADJUST']));
        Constraints::check('subscriptions', 'subscriptions_range_chk', sprintf('%s > %s', Constraints::wrap('expires_at'), Constraints::wrap('starts_at')));

        Schema::create('subscription_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('type', 24);
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('created_at');

            $table->index(['tenant_id', 'created_at']);
        });
        Constraints::check('subscription_events', 'sub_events_type_chk', Constraints::in('type', [
            'CREATED', 'ACTIVATED', 'EXTENDED', 'SUSPENDED', 'RESUMED', 'DEACTIVATED', 'EXPIRED', 'LIMIT_CHANGED',
        ]));

        Schema::create('system_settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->json('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->datetimes();
        });

        Schema::create('firmware_releases', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('version', 32)->unique();
            $table->char('sha256', 64);
            $table->string('file_path', 255);
            $table->unsignedInteger('size');
            $table->text('notes')->nullable();
            $table->boolean('is_published')->default(false);
            $table->dateTime('published_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->datetimes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firmware_releases');
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('subscription_events');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('subscription_payments');
    }
};
