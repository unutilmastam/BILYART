<?php

namespace App\Domain\Telegram\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class TelegramChat extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $fillable = ['integration_id', 'chat_id', 'title', 'branch_id', 'receives_daily_report', 'receives_alerts'];

    protected function casts(): array
    {
        return [
            'chat_id' => 'integer',
            'receives_daily_report' => 'boolean',
            'receives_alerts' => 'boolean',
        ];
    }
}
