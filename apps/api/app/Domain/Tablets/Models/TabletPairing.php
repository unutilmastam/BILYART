<?php

namespace App\Domain\Tablets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pairing handshake of an unpaired tablet. Platform-level; only touched by TabletPairingService. */
class TabletPairing extends Model
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

    /** @return BelongsTo<Tablet, $this> */
    public function tablet(): BelongsTo
    {
        return $this->belongsTo(Tablet::class);
    }
}
