<?php

namespace App\Domain\Branches\Models;

use App\Domain\Branches\Enums\PaymentMode;
use App\Domain\Tables\Models\BilliardTable;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Support\Concerns\HasPublicId;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UseFactory(BranchFactory::class)]
class Branch extends Model
{
    use BelongsToTenant, HasFactory, HasPublicId;

    protected $fillable = ['name', 'address', 'phone', 'timezone', 'is_active', 'report_time', 'settings', 'payment_mode'];

    protected $attributes = [
        'is_active' => true,
        'timezone' => 'Asia/Tashkent',
        'report_time' => '23:30:00',
        'payment_mode' => 'CASHIER',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'settings' => 'array',
            'payment_mode' => PaymentMode::class,
        ];
    }

    /** @return HasMany<BilliardTable, $this> */
    public function tables(): HasMany
    {
        return $this->hasMany(BilliardTable::class);
    }

    /** @return HasMany<WorkingHour, $this> */
    public function workingHours(): HasMany
    {
        return $this->hasMany(WorkingHour::class);
    }

    /** @return HasMany<BranchClosedDay, $this> */
    public function closedDays(): HasMany
    {
        return $this->hasMany(BranchClosedDay::class);
    }
}
