<?php

namespace App\Domain\Subscriptions\Models;

use App\Domain\Subscriptions\Enums\SubscriptionSource;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One added subscription period (history row). */
class Subscription extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $fillable = ['starts_at', 'expires_at', 'days', 'source', 'payment_id', 'reason', 'created_by'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'days' => 'integer',
            'source' => SubscriptionSource::class,
        ];
    }

    /** @return BelongsTo<SubscriptionPayment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPayment::class);
    }
}
