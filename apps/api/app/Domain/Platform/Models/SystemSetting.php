<?php

namespace App\Domain\Platform\Models;

use Illuminate\Database\Eloquent\Model;

/** Platform-wide key/value settings managed by the Super Admin. */
class SystemSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value', 'updated_by'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
