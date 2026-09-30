<?php

use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Statuses that hold the table. Must match App\Domain\Sessions\Enums\SessionStatus::occupying(). */
    private const OCCUPYING = ['RESERVED', 'STARTING', 'ACTIVE', 'COMPLETING'];

    public function up(): void
    {
        $w = [Constraints::class, 'wrap'];
        $occupying = Constraints::in('status', self::OCCUPYING);

        Schema::create('game_sessions', function (Blueprint $table) use ($w, $occupying) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('table_id');
            $table->unsignedBigInteger('device_id')->nullable();
            $table->unsignedBigInteger('tablet_id')->nullable();
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->string('status', 16);
            $table->unsignedSmallInteger('duration_minutes');
            $table->dateTime('reserved_until')->nullable();
            $table->dateTime('start_at')->nullable();
            $table->dateTime('end_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->boolean('ended_early')->default(false);
            $table->dateTime('warned_at')->nullable();
            $table->unsignedBigInteger('price_per_hour_snapshot'); // UZS
            $table->unsignedInteger('rounding_step_snapshot');
            $table->unsignedBigInteger('amount'); // UZS
            $table->string('payment_status', 16)->default('UNPAID');
            $table->unsignedBigInteger('payment_marked_by')->nullable();
            $table->dateTime('payment_marked_at')->nullable();
            $table->unsignedBigInteger('stopped_by')->nullable();
            $table->string('failure_reason', 64)->nullable();
            $table->datetimes();

            if (! Constraints::isPostgres()) {
                // Double-booking guard (spec §32): at most one occupying session per table.
                $table->unsignedBigInteger('table_lock')->nullable()
                    ->storedAs(sprintf('CASE WHEN %s THEN %s ELSE NULL END', $occupying, $w('table_id')))
                    ->unique('game_sessions_table_lock_unique');
            }

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'branch_id', 'created_at']);
            $table->index(['tenant_id', 'status']);
            $table->index(['status', 'end_at']);
            $table->index(['table_id', 'status']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'branch_id', 'table_id'], 'game_sessions_table_fk')->references(['tenant_id', 'branch_id', 'id'])->on('billiard_tables')->restrictOnDelete();
            $table->foreign(['tenant_id', 'device_id'], 'game_sessions_device_fk')->references(['tenant_id', 'id'])->on('devices')->restrictOnDelete();
            $table->foreign(['tenant_id', 'tablet_id'], 'game_sessions_tablet_fk')->references(['tenant_id', 'id'])->on('tablets')->restrictOnDelete();
            $table->foreign(['tenant_id', 'pricing_plan_id'], 'game_sessions_plan_fk')->references(['tenant_id', 'id'])->on('pricing_plans')->restrictOnDelete();
            $table->foreign(['tenant_id', 'payment_marked_by'], 'game_sessions_paid_by_fk')->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
            $table->foreign(['tenant_id', 'stopped_by'], 'game_sessions_stopped_by_fk')->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        });

        if (Constraints::isPostgres()) {
            DB::statement(sprintf('CREATE UNIQUE INDEX game_sessions_table_lock_unique ON game_sessions (table_id) WHERE %s', $occupying));
        }

        Constraints::check('game_sessions', 'game_sessions_status_chk', Constraints::in('status', [
            'RESERVED', 'STARTING', 'ACTIVE', 'COMPLETING', 'COMPLETED', 'CANCELLED', 'FAILED',
        ]));
        Constraints::check('game_sessions', 'game_sessions_payment_chk', Constraints::in('payment_status', ['UNPAID', 'PAID', 'WAIVED']));
        Constraints::check('game_sessions', 'game_sessions_duration_chk', sprintf('%s BETWEEN 1 AND 720', $w('duration_minutes')));
        Constraints::check('game_sessions', 'game_sessions_times_chk', sprintf(
            '(%1$s IS NULL AND %2$s IS NULL) OR (%1$s IS NOT NULL AND %2$s IS NOT NULL AND %2$s > %1$s)',
            $w('start_at'), $w('end_at')
        ));
        Constraints::check('game_sessions', 'game_sessions_started_chk', sprintf(
            "%s NOT IN ('STARTING', 'ACTIVE', 'COMPLETING', 'COMPLETED') OR %s IS NOT NULL",
            $w('status'), $w('start_at')
        ));

        Schema::create('session_photos', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('session_id')->unique(); // one photo per session
            $table->string('storage_path', 255);
            $table->string('mime_type', 32);
            $table->unsignedInteger('size');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->char('sha256', 64);
            $table->dateTime('created_at');
            $table->dateTime('deleted_at')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable(); // NULL + deleted_at = retention job
            $table->string('delete_reason', 32)->nullable();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'created_at']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'session_id'], 'session_photos_session_fk')->references(['tenant_id', 'id'])->on('game_sessions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'deleted_by'], 'session_photos_deleted_by_fk')->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('session_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('session_id');
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->string('actor_type', 16); // USER / TABLET / DEVICE / SYSTEM
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('reason', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('created_at');

            $table->index(['session_id', 'id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'session_id'], 'session_events_session_fk')->references(['tenant_id', 'id'])->on('game_sessions')->restrictOnDelete();
        });
        Constraints::check('session_events', 'session_events_actor_chk', Constraints::in('actor_type', ['USER', 'TABLET', 'DEVICE', 'SYSTEM']));
    }

    public function down(): void
    {
        Schema::dropIfExists('session_events');
        Schema::dropIfExists('session_photos');
        Schema::dropIfExists('game_sessions');
    }
};
