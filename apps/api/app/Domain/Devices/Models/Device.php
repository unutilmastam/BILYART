<?php

namespace App\Domain\Devices\Models;

use App\Domain\Branches\Models\Branch;
use App\Domain\Devices\Enums\DeviceKind;
use App\Domain\Devices\Enums\DeviceStatus;
use App\Domain\Tables\Models\BilliardTable;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Support\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ESP32 registration row (see migrations 000006 + 2026_10_05 for the lifecycle).
 * One device belongs to a branch and drives channel_count relay channels; each
 * table points to (device_id, device_channel). Online status is derived from
 * last_seen_at — never stored or faked.
 */
class Device extends Model
{
    use BelongsToTenant, HasPublicId;

    protected bool $tenantNullable = true;

    protected $fillable = ['firmware_version'];

    protected $hidden = ['token_hash'];

    protected $attributes = ['kind' => 'LIGHT'];

    protected function casts(): array
    {
        return [
            'status' => DeviceStatus::class,
            'kind' => DeviceKind::class,
            'channel_count' => 'integer',
            'last_state' => 'array',
            'last_seen_at' => 'immutable_datetime',
            'offline_since' => 'immutable_datetime',
            'registered_at' => 'immutable_datetime',
            'paired_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function isOnline(?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();
        $timeout = (int) config('devices.online_timeout_sec', 15);

        return $this->last_seen_at !== null && $this->last_seen_at->greaterThanOrEqualTo($now->subSeconds($timeout));
    }

    public static function codeFor(string $hardwareId): string
    {
        return 'ESP32-'.substr(strtoupper($hardwareId), -6);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Tables wired to this device's relay channels. @return HasMany<BilliardTable, $this> */
    public function tables(): HasMany
    {
        return $this->hasMany(BilliardTable::class, 'device_id')->orderBy('device_channel');
    }

    public const MAX_CHANNELS = 8;

    public function isCash(): bool
    {
        return $this->kind === DeviceKind::CASH;
    }
}
