<?php

use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Device and tablet rows are *registrations*: tenant_id is NULL until pairing,
 * then set once and never changed. Unpairing revokes the row; re-pairing
 * (possibly to another tenant) creates a new row. This keeps composite FKs from
 * sessions/commands valid forever. Uniqueness of the physical device / table
 * among live rows is enforced by the nullable "active_*" columns (NULL = released).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->char('hardware_id', 12); // eFuse MAC, uppercase hex
            $table->char('active_hardware_id', 12)->nullable()->unique(); // = hardware_id while UNPAIRED/PAIRED
            $table->string('device_code', 16); // ESP32-XXXXXX
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('table_id')->nullable();
            $table->unsignedBigInteger('active_table_id')->nullable()->unique(); // = table_id while PAIRED
            $table->string('status', 16)->default('UNPAIRED');
            $table->char('token_hash', 64)->nullable(); // sha256 of the device token
            $table->string('firmware_version', 32)->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->json('last_state')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->dateTime('registered_at');
            $table->dateTime('paired_at')->nullable();
            $table->unsignedBigInteger('paired_by')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->datetimes();

            $table->unique(['tenant_id', 'id']);
            $table->index('hardware_id');
            $table->index(['tenant_id', 'status']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'branch_id', 'table_id'], 'devices_table_fk')->references(['tenant_id', 'branch_id', 'id'])->on('billiard_tables')->restrictOnDelete();
            $table->foreign(['tenant_id', 'paired_by'], 'devices_paired_by_fk')->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
            $table->foreign(['tenant_id', 'revoked_by'], 'devices_revoked_by_fk')->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        });
        Constraints::check('devices', 'devices_status_chk', Constraints::in('status', ['UNPAIRED', 'PAIRED', 'REVOKED']));
        $w = [Constraints::class, 'wrap'];
        Constraints::check('devices', 'devices_paired_chk', sprintf(
            "(%1\$s = 'PAIRED' AND %2\$s IS NOT NULL AND %3\$s IS NOT NULL AND %4\$s IS NOT NULL AND %5\$s = %4\$s AND %6\$s IS NOT NULL) OR (%1\$s = 'UNPAIRED' AND %2\$s IS NULL AND %5\$s IS NULL AND %6\$s IS NOT NULL) OR (%1\$s = 'REVOKED' AND %5\$s IS NULL AND %6\$s IS NULL)",
            $w('status'), $w('tenant_id'), $w('branch_id'), $w('table_id'), $w('active_table_id'), $w('active_hardware_id')
        ));

        Schema::create('device_pairings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->restrictOnDelete();
            $table->char('code_hash', 64); // HMAC-SHA256(app key, 6-digit code)
            $table->char('poll_token_hash', 64)->unique();
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable(); // set when used
            $table->unsignedBigInteger('used_by')->nullable();
            $table->dateTime('token_delivered_at')->nullable();
            $table->datetimes();

            $table->index(['code_hash', 'expires_at']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'used_by'], 'device_pairings_user_fk')->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('tablets', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('device_code', 16)->unique(); // TABLET-XXXXXX
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('name', 100)->nullable();
            $table->string('status', 16)->default('UNPAIRED');
            $table->char('token_hash', 64)->nullable()->unique(); // sha256 of the bearer token issued at pairing
            $table->string('app_version', 32)->nullable();
            $table->string('device_model', 100)->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->dateTime('registered_at');
            $table->dateTime('paired_at')->nullable();
            $table->unsignedBigInteger('paired_by')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->datetimes();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'branch_id', 'status']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'branch_id'], 'tablets_branch_fk')->references(['tenant_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['tenant_id', 'paired_by'], 'tablets_paired_by_fk')->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
            $table->foreign(['tenant_id', 'revoked_by'], 'tablets_revoked_by_fk')->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        });
        Constraints::check('tablets', 'tablets_status_chk', Constraints::in('status', ['UNPAIRED', 'PAIRED', 'REVOKED']));
        Constraints::check('tablets', 'tablets_paired_chk', sprintf(
            "%1\$s <> 'PAIRED' OR (%2\$s IS NOT NULL AND %3\$s IS NOT NULL)",
            $w('status'), $w('tenant_id'), $w('branch_id')
        ));

        Schema::create('tablet_pairings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tablet_id')->constrained('tablets')->restrictOnDelete();
            $table->char('code_hash', 64);
            $table->char('poll_token_hash', 64)->unique();
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('used_by')->nullable();
            $table->dateTime('token_delivered_at')->nullable();
            $table->datetimes();

            $table->index(['code_hash', 'expires_at']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'used_by'], 'tablet_pairings_user_fk')->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tablet_pairings');
        Schema::dropIfExists('tablets');
        Schema::dropIfExists('device_pairings');
        Schema::dropIfExists('devices');
    }
};
