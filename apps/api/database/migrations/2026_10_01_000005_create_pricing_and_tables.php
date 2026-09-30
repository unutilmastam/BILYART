<?php

use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('branch_id')->nullable(); // NULL = usable in every branch of the tenant
            $table->string('name', 150);
            $table->string('type', 16)->default('HOURLY'); // strategy key; future models add values + a PriceCalculator strategy
            $table->unsignedBigInteger('price_per_hour'); // UZS
            $table->unsignedInteger('rounding_step')->default(1000); // UZS
            $table->json('allowed_durations'); // minutes, e.g. [30,60,90,120]
            $table->json('rules')->nullable(); // parameters of future pricing models
            $table->boolean('is_active')->default(true);
            $table->datetimes();

            $table->unique(['tenant_id', 'id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'branch_id'], 'pricing_plans_branch_fk')->references(['tenant_id', 'id'])->on('branches')->restrictOnDelete();
        });
        Constraints::check('pricing_plans', 'pricing_plans_type_chk', Constraints::in('type', ['HOURLY']));
        Constraints::check('pricing_plans', 'pricing_plans_rounding_chk', sprintf('%s >= 1', Constraints::wrap('rounding_step')));

        Schema::create('billiard_tables', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedSmallInteger('number');
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->datetimes();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'branch_id', 'id']);
            $table->unique(['tenant_id', 'branch_id', 'number']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'branch_id'], 'billiard_tables_branch_fk')->references(['tenant_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['tenant_id', 'pricing_plan_id'], 'billiard_tables_plan_fk')->references(['tenant_id', 'id'])->on('pricing_plans')->restrictOnDelete();
        });
        Constraints::check('billiard_tables', 'billiard_tables_number_chk', sprintf('%s >= 1', Constraints::wrap('number')));
    }

    public function down(): void
    {
        Schema::dropIfExists('billiard_tables');
        Schema::dropIfExists('pricing_plans');
    }
};
