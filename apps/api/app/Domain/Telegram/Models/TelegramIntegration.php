<?php

namespace App\Domain\Telegram\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Per-tenant bot. The token is encrypted at rest and never serialized (spec §43.15). */
class TelegramIntegration extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $fillable = ['bot_username', 'is_active'];

    protected $hidden = ['bot_token_encrypted', 'webhook_secret_hash', 'link_code_hash'];

    protected function casts(): array
    {
        return [
            'bot_token_encrypted' => 'encrypted',
            'is_active' => 'boolean',
            'link_code_expires_at' => 'immutable_datetime',
            'last_error_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<TelegramChat, $this> */
    public function chats(): HasMany
    {
        return $this->hasMany(TelegramChat::class, 'integration_id');
    }
}
