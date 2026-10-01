<?php

namespace App\Domain\Subscriptions\Models;

use App\Domain\Subscriptions\Enums\PaymentRequestStatus;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Users\Models\User;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A client's report of a subscription payment, waiting for the Super Admin (amounts: integer UZS). */
class SubscriptionPaymentRequest extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $fillable = ['months', 'branch_count', 'price_per_branch', 'amount', 'note'];

    protected $hidden = ['receipt_path'];

    protected function casts(): array
    {
        return [
            'months' => 'integer',
            'branch_count' => 'integer',
            'price_per_branch' => 'integer',
            'amount' => 'integer',
            'status' => PaymentRequestStatus::class,
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
