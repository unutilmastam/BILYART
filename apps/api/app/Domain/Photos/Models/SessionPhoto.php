<?php

namespace App\Domain\Photos\Models;

use App\Domain\Sessions\Models\GameSession;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Customer photo metadata; the file lives in private storage (spec §22). deleted_at marks removal (file gone). */
class SessionPhoto extends Model
{
    use BelongsToTenant, HasPublicId;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['storage_path'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'created_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    /** @return BelongsTo<GameSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(GameSession::class, 'session_id');
    }
}
