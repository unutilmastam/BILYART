<?php

namespace App\Domain\Cash\Models;

use App\Domain\Cash\Enums\CashNoteStatus;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/** One bill accepted by a branch's bill acceptor (money is integer UZS). */
class CashNote extends Model
{
    use BelongsToTenant, HasPublicId;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => CashNoteStatus::class,
            'nominal' => 'integer',
            'device_ts' => 'integer',
            'received_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
