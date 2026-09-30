<?php

namespace App\Domain\Notifications\Models;

use App\Domain\Notifications\Enums\Severity;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Database-backed notification (spec §40). dedupe_key is UNIQUE so the same event is never notified twice. */
class Notification extends Model
{
    use BelongsToTenant, HasPublicId;

    protected bool $tenantNullable = true;

    public const UPDATED_AT = null;

    protected $fillable = ['type', 'severity', 'dedupe_key', 'payload'];

    protected function casts(): array
    {
        return [
            'severity' => Severity::class,
            'payload' => 'array',
            'created_at' => 'immutable_datetime',
            'read_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<NotificationLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(NotificationLog::class);
    }
}
