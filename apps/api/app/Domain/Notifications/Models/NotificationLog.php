<?php

namespace App\Domain\Notifications\Models;

use App\Domain\Notifications\Enums\NotificationChannel;
use Illuminate\Database\Eloquent\Model;

class NotificationLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['notification_id', 'channel', 'target', 'status', 'error', 'sent_at'];

    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'created_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
        ];
    }
}
