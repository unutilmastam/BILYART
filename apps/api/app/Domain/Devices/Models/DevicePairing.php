<?php

namespace App\Domain\Devices\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pairing handshake of an unpaired device. Platform-level (no tenant until used); only touched by DevicePairingService. */
class DevicePairing extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'token_delivered_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
