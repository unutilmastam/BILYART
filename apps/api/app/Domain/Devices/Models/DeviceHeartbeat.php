<?php

namespace App\Domain\Devices\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Raw heartbeat (kept 7 days, pruned by the scheduler). */
class DeviceHeartbeat extends Model
{
    use BelongsToTenant;

    protected bool $tenantNullable = true;

    public $timestamps = false;

    protected $fillable = ['device_id', 'received_at', 'state', 'session_public_id', 'rssi', 'uptime', 'fw', 'boot_reason'];

    protected function casts(): array
    {
        return ['received_at' => 'immutable_datetime'];
    }
}
