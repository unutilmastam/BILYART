<?php

use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_commands', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique(); // = commandId on the wire
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('device_id');
            $table->unsignedBigInteger('session_id')->nullable();
            $table->string('type', 16);
            $table->json('payload');
            $table->string('status', 16)->default('PENDING');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('created_at');
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('acked_at')->nullable();
            $table->dateTime('expires_at');
            $table->string('result', 16)->nullable();
            $table->string('last_error', 200)->nullable();

            $table->index(['device_id', 'status']);
            $table->index(['status', 'expires_at']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'device_id'], 'device_commands_device_fk')->references(['tenant_id', 'id'])->on('devices')->restrictOnDelete();
            $table->foreign(['tenant_id', 'session_id'], 'device_commands_session_fk')->references(['tenant_id', 'id'])->on('game_sessions')->restrictOnDelete();
        });
        Constraints::check('device_commands', 'device_commands_type_chk', Constraints::in('type', [
            'START_SESSION', 'STOP_SESSION', 'WARNING', 'SYNC', 'PING', 'CONFIG_UPDATE', 'OTA',
        ]));
        Constraints::check('device_commands', 'device_commands_status_chk', Constraints::in('status', [
            'PENDING', 'SENT', 'ACKNOWLEDGED', 'FAILED', 'EXPIRED',
        ]));

        Schema::create('device_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->restrictOnDelete();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->dateTime('received_at');
            $table->string('state', 8);
            $table->char('session_public_id', 26)->nullable();
            $table->smallInteger('rssi')->nullable();
            $table->unsignedInteger('uptime')->nullable();
            $table->string('fw', 32)->nullable();
            $table->string('boot_reason', 32)->nullable();

            $table->index(['device_id', 'received_at']);
            $table->index('received_at');
            $table->foreign(['tenant_id', 'device_id'], 'device_heartbeats_device_fk')->references(['tenant_id', 'id'])->on('devices')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_heartbeats');
        Schema::dropIfExists('device_commands');
    }
};
