<?php

namespace App\Support\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** ULID `public_id` used in URLs/APIs instead of the internal numeric id. */
trait HasPublicId
{
    public static function bootHasPublicId(): void
    {
        static::creating(function (Model $model): void {
            if (empty($model->getAttribute('public_id'))) {
                $model->setAttribute('public_id', (string) Str::ulid());
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
