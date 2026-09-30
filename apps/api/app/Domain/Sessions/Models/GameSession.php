<?php

namespace App\Domain\Sessions\Models;

use App\Domain\Branches\Models\Branch;
use App\Domain\Devices\Models\Device;
use App\Domain\Photos\Models\SessionPhoto;
use App\Domain\Sessions\Enums\PaymentStatus;
use App\Domain\Sessions\Enums\SessionStatus;
use App\Domain\Tables\Models\BilliardTable;
use App\Domain\Tablets\Models\Tablet;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A billiard game session. `status` changes only through SessionService /
 * SessionStateMachine (never directly). Money fields are integer UZS.
 *
 * @property SessionStatus $status
 */
class GameSession extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $table = 'game_sessions';

    /** Nothing is mass-assignable: sessions are built field by field in SessionService. */
    protected $guarded = ['*'];

    protected $attributes = [
        'payment_status' => 'UNPAID',
        'ended_early' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => SessionStatus::class,
            'payment_status' => PaymentStatus::class,
            'duration_minutes' => 'integer',
            'reserved_until' => 'immutable_datetime',
            'start_at' => 'immutable_datetime',
            'end_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'warned_at' => 'immutable_datetime',
            'payment_marked_at' => 'immutable_datetime',
            'ended_early' => 'boolean',
            'price_per_hour_snapshot' => 'integer',
            'rounding_step_snapshot' => 'integer',
            'amount' => 'integer',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<BilliardTable, $this> */
    public function table(): BelongsTo
    {
        return $this->belongsTo(BilliardTable::class, 'table_id');
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return BelongsTo<Tablet, $this> */
    public function tablet(): BelongsTo
    {
        return $this->belongsTo(Tablet::class);
    }

    /** @return HasOne<SessionPhoto, $this> */
    public function photo(): HasOne
    {
        return $this->hasOne(SessionPhoto::class, 'session_id');
    }

    /** @return HasMany<SessionEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(SessionEvent::class, 'session_id')->orderBy('id');
    }
}
