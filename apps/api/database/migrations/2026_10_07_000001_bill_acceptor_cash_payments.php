<?php

use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cash payment through a bill acceptor (owner request 2026-10-05).
 *  - devices.kind: LIGHT (relay controller, as before) or CASH (one bill acceptor per branch).
 *  - branches.payment_mode: CASHIER (staff marks payment, as before) or BILL_ACCEPTOR.
 *  - game_sessions: a RESERVED session in a BILL_ACCEPTOR branch collects cash (cash_paid) until
 *    paying_until; the table stays held by the existing double-booking guard (RESERVED occupies).
 *  - cash_notes: every accepted bill, unique per device (note_uid) so a re-sent bill is never counted twice.
 *  - cash_collections: staff emptied the cash box (expected vs counted).
 */
return new class extends Migration
{
    private const NOMINALS = [1000, 2000, 5000, 10000, 20000, 50000, 100000, 200000];

    public function up(): void
    {
        $w = [Constraints::class, 'wrap'];

        Schema::table('devices', fn (Blueprint $table) => $table->string('kind', 8)->default('LIGHT')->after('device_code'));
        Constraints::check('devices', 'devices_kind_chk', Constraints::in('kind', ['LIGHT', 'CASH']));

        Schema::table('branches', fn (Blueprint $table) => $table->string('payment_mode', 16)->default('CASHIER'));
        Constraints::check('branches', 'branches_payment_mode_chk', Constraints::in('payment_mode', ['CASHIER', 'BILL_ACCEPTOR']));

        Schema::table('game_sessions', function (Blueprint $table) {
            $table->string('payment_source', 16)->nullable()->after('payment_status');
            $table->unsignedBigInteger('cash_paid')->default(0)->after('payment_source'); // UZS
            $table->unsignedBigInteger('cash_device_id')->nullable()->after('cash_paid');
            $table->dateTime('paying_until')->nullable()->after('cash_device_id');
            $table->index(['cash_device_id', 'status']);
            $table->foreign(['tenant_id', 'cash_device_id'], 'game_sessions_cash_device_fk')->references(['tenant_id', 'id'])->on('devices')->restrictOnDelete();
        });
        Constraints::check('game_sessions', 'game_sessions_payment_source_chk', sprintf(
            '%1$s IS NULL OR %1$s IN (\'CASHIER\', \'BILL_ACCEPTOR\')', $w('payment_source')
        ));

        Schema::create('cash_collections', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('device_id');
            $table->unsignedBigInteger('expected_amount'); // UZS, sum of the notes taken out
            $table->unsignedBigInteger('counted_amount');  // UZS, what staff counted in the box
            $table->unsignedInteger('notes_count');
            $table->unsignedBigInteger('collected_by');
            $table->string('comment', 300)->nullable();
            $table->dateTime('created_at');

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'branch_id', 'created_at']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'branch_id'], 'cash_collections_branch_fk')->references(['tenant_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['tenant_id', 'device_id'], 'cash_collections_device_fk')->references(['tenant_id', 'id'])->on('devices')->restrictOnDelete();
            $table->foreign(['tenant_id', 'collected_by'], 'cash_collections_user_fk')->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('cash_notes', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('device_id');
            $table->unsignedBigInteger('session_id')->nullable();
            $table->string('note_uid', 48);
            $table->unsignedInteger('nominal'); // UZS
            $table->string('status', 16);
            $table->unsignedBigInteger('device_ts')->nullable(); // device clock, display only
            $table->dateTime('received_at');
            $table->unsignedBigInteger('collection_id')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->string('resolve_comment', 300)->nullable();

            $table->unique(['device_id', 'note_uid']); // a re-sent bill (after reboot / lost reply) is never counted twice
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'branch_id', 'received_at']);
            $table->index(['device_id', 'collection_id']);
            $table->index(['tenant_id', 'status']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'branch_id'], 'cash_notes_branch_fk')->references(['tenant_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['tenant_id', 'device_id'], 'cash_notes_device_fk')->references(['tenant_id', 'id'])->on('devices')->restrictOnDelete();
            $table->foreign(['tenant_id', 'session_id'], 'cash_notes_session_fk')->references(['tenant_id', 'id'])->on('game_sessions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'collection_id'], 'cash_notes_collection_fk')->references(['tenant_id', 'id'])->on('cash_collections')->restrictOnDelete();
            $table->foreign(['tenant_id', 'resolved_by'], 'cash_notes_resolver_fk')->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        });
        Constraints::check('cash_notes', 'cash_notes_status_chk', Constraints::in('status', ['CREDITED', 'UNASSIGNED', 'RESOLVED']));
        Constraints::check('cash_notes', 'cash_notes_nominal_chk', sprintf('%s IN (%s)', $w('nominal'), implode(', ', self::NOMINALS)));
        Constraints::check('cash_notes', 'cash_notes_credited_chk', sprintf(
            "%s <> 'CREDITED' OR %s IS NOT NULL", $w('status'), $w('session_id')
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_notes');
        Schema::dropIfExists('cash_collections');
        Constraints::dropCheck('game_sessions', 'game_sessions_payment_source_chk');
        Schema::table('game_sessions', function (Blueprint $table) {
            $table->dropForeign('game_sessions_cash_device_fk');
            $table->dropIndex(['cash_device_id', 'status']);
            $table->dropColumn(['payment_source', 'cash_paid', 'cash_device_id', 'paying_until']);
        });
        Constraints::dropCheck('branches', 'branches_payment_mode_chk');
        Schema::table('branches', fn (Blueprint $table) => $table->dropColumn('payment_mode'));
        Constraints::dropCheck('devices', 'devices_kind_chk');
        Schema::table('devices', fn (Blueprint $table) => $table->dropColumn('kind'));
    }
};
