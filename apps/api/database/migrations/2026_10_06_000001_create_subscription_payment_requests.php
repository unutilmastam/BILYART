<?php

use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A client owner reports a subscription payment (card/bank transfer) with a receipt photo;
 * the Super Admin approves it (→ subscription_payments + extension) or rejects it.
 * Price is per active branch per month (platform setting), snapshotted on the request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_payment_requests', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedTinyInteger('months');
            $table->unsignedInteger('branch_count');
            $table->unsignedBigInteger('price_per_branch'); // UZS per branch per month, snapshot
            $table->unsignedBigInteger('amount');           // UZS = months × branches × price
            $table->string('status', 16)->default('PENDING');
            $table->string('receipt_path', 191);
            $table->char('receipt_sha256', 64);
            $table->string('note', 500)->nullable();
            $table->string('reject_reason', 500)->nullable();
            $table->unsignedBigInteger('created_by');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->foreignId('payment_id')->nullable()->unique()->constrained('subscription_payments')->restrictOnDelete();
            $table->datetimes();

            $table->unique(['tenant_id', 'id']);
            $table->index(['status', 'created_at']);
            $table->foreign(['tenant_id', 'created_by'], 'sub_pay_req_creator_fk')->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        });
        Constraints::check('subscription_payment_requests', 'sub_pay_req_status_chk', Constraints::in('status', ['PENDING', 'APPROVED', 'REJECTED', 'CANCELLED']));
        $w = [Constraints::class, 'wrap'];
        Constraints::check('subscription_payment_requests', 'sub_pay_req_months_chk', sprintf('%1$s >= 1 AND %1$s <= 12', $w('months')));
        Constraints::check('subscription_payment_requests', 'sub_pay_req_review_chk', sprintf(
            "(%1\$s = 'APPROVED' AND %2\$s IS NOT NULL AND %3\$s IS NOT NULL) OR (%1\$s <> 'APPROVED' AND %2\$s IS NULL)",
            $w('status'), $w('payment_id'), $w('reviewed_by')
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payment_requests');
    }
};
