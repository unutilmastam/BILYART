<?php

use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_integrations', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->unique()->constrained('tenants')->restrictOnDelete();
            $table->text('bot_token_encrypted'); // Crypt (APP_KEY); never returned by the API
            $table->string('bot_username', 64)->nullable();
            $table->char('webhook_secret_hash', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->char('link_code_hash', 64)->nullable();
            $table->dateTime('link_code_expires_at')->nullable();
            $table->dateTime('last_error_at')->nullable();
            $table->string('last_error', 200)->nullable();
            $table->datetimes();

            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('telegram_chats', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('integration_id');
            $table->bigInteger('chat_id');
            $table->string('title', 150)->nullable();
            $table->unsignedBigInteger('branch_id')->nullable(); // NULL = all branches
            $table->boolean('receives_daily_report')->default(true);
            $table->boolean('receives_alerts')->default(true);
            $table->datetimes();

            $table->unique(['integration_id', 'chat_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'integration_id'], 'telegram_chats_integration_fk')->references(['tenant_id', 'id'])->on('telegram_integrations')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'branch_id'], 'telegram_chats_branch_fk')->references(['tenant_id', 'id'])->on('branches')->restrictOnDelete();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete(); // NULL = platform (Super Admin)
            $table->string('type', 48);
            $table->string('severity', 8)->default('INFO');
            $table->string('dedupe_key', 191)->unique();
            $table->json('payload');
            $table->dateTime('created_at');
            $table->dateTime('read_at')->nullable();

            $table->index(['tenant_id', 'created_at']);
        });
        Constraints::check('notifications', 'notifications_severity_chk', Constraints::in('severity', ['INFO', 'WARNING', 'CRITICAL']));

        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained('notifications')->cascadeOnDelete();
            $table->string('channel', 16);
            $table->string('target', 64)->nullable(); // e.g. telegram chat public id
            $table->string('status', 16);
            $table->string('error', 200)->nullable();
            $table->dateTime('created_at');
            $table->dateTime('sent_at')->nullable();

            $table->index(['notification_id', 'channel']);
        });
        Constraints::check('notification_logs', 'notification_logs_channel_chk', Constraints::in('channel', ['IN_APP', 'TELEGRAM']));
        Constraints::check('notification_logs', 'notification_logs_status_chk', Constraints::in('status', ['PENDING', 'SENT', 'FAILED', 'SKIPPED']));
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('telegram_chats');
        Schema::dropIfExists('telegram_integrations');
    }
};
