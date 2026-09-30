<?php

namespace App\Domain\Platform\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/** ESP32 firmware binary published by the Super Admin (spec §55). sha256 is computed server-side. */
class FirmwareRelease extends Model
{
    use HasPublicId;

    protected $fillable = ['version', 'notes'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'is_published' => 'boolean',
            'published_at' => 'immutable_datetime',
        ];
    }
}
