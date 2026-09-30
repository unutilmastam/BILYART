<?php

namespace App\Domain\Subscriptions\Models;

use App\Domain\Subscriptions\Enums\PaymentMethod;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/** Manual payment from a client to the platform owner (spec §4). amount = integer UZS. */
class SubscriptionPayment extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $fillable = ['amount', 'method', 'note', 'paid_at', 'recorded_by'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'method' => PaymentMethod::class,
            'paid_at' => 'immutable_datetime',
        ];
    }
}
