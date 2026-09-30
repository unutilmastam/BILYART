<?php

use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant isolation at DB level: children reference parents through composite
 * foreign keys (tenant_id, parent_id) → parent(tenant_id, id). See docs/DATABASE.md §1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('name', 150);
            $table->string('address', 255)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('timezone', 64)->default('Asia/Tashkent');
            $table->boolean('is_active')->default(true);
            $table->time('report_time')->default('23:30:00');
            $table->json('settings')->nullable();
            $table->datetimes();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('working_hours', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedTinyInteger('weekday'); // ISO-8601: 1 = Monday … 7 = Sunday
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable(); // closes_at <= opens_at means "crosses midnight"
            $table->boolean('is_closed')->default(false);
            $table->datetimes();

            $table->unique(['branch_id', 'weekday']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'branch_id'], 'working_hours_branch_fk')->references(['tenant_id', 'id'])->on('branches')->restrictOnDelete();
        });
        Constraints::check('working_hours', 'working_hours_weekday_chk', sprintf('%s BETWEEN 1 AND 7', Constraints::wrap('weekday')));

        Schema::create('branch_closed_days', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('branch_id');
            $table->date('date');
            $table->string('reason', 255)->nullable();
            $table->datetimes();

            $table->unique(['branch_id', 'date']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'branch_id'], 'closed_days_branch_fk')->references(['tenant_id', 'id'])->on('branches')->restrictOnDelete();
        });

        Schema::create('user_branch_access', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('branch_id');
            $table->dateTime('created_at');

            $table->primary(['user_id', 'branch_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'user_id'], 'uba_user_fk')->references(['tenant_id', 'id'])->on('users')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'branch_id'], 'uba_branch_fk')->references(['tenant_id', 'id'])->on('branches')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_branch_access');
        Schema::dropIfExists('branch_closed_days');
        Schema::dropIfExists('working_hours');
        Schema::dropIfExists('branches');
    }
};
