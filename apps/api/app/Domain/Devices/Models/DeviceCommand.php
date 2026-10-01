<?php

namespace App\Domain\Devices\Models;

use App\Domain\Devices\Enums\CommandStatus;
use App\Domain\Devices\Enums\CommandType;
use App\Domain\Sessions\Models\GameSession;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Command queued for a device (spec §34). public_id is the wire commandId. */
class DeviceCommand extends Model
{
    use BelongsToTenant, HasPublicId;

    public const UPDATED_AT = null;

    protected $fillable = ['device_id', 'session_id', 'channel', 'type', 'payload', 'expires_at'];

    protected $attributes = ['status' => 'PENDING', 'attempts' => 0];

    protected function casts(): array
    {
        return [
            'type' => CommandType::class,
            'status' => CommandStatus::class,
            'payload' => 'array',
            'attempts' => 'integer',
            'channel' => 'integer',
            'created_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'acked_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return BelongsTo<GameSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(GameSession::class, 'session_id');
    }
}
