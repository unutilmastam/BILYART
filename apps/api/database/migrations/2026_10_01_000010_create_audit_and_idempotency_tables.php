<?php

use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete();
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 64);
            $table->string('entity_type', 48)->nullable();
            $table->string('entity_id', 26)->nullable(); // public id
            $table->json('metadata')->nullable(); // never secrets or photos
            $table->string('ip', 45)->nullable();
            $table->string('request_id', 36)->nullable();
            $table->dateTime('created_at');

            $table->index(['tenant_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
        });
        Constraints::check('audit_logs', 'audit_logs_actor_chk', Constraints::in('actor_type', ['USER', 'DEVICE', 'TABLET', 'SYSTEM']));

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('principal_type', 16);
            $table->unsignedBigInteger('principal_id');
            $table->string('key', 64);
            $table->string('route', 191);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_code')->nullable(); // NULL = in flight
            $table->longText('response_body')->nullable();
            $table->dateTime('created_at');

            $table->unique(['principal_type', 'principal_id', 'key']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('audit_logs');
    }
};
