<?php

namespace App\Domain\Tables\Models;

use App\Domain\Branches\Models\Branch;
use App\Domain\Devices\Models\Device;
use App\Domain\Pricing\Models\PricingPlan;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Support\Concerns\HasPublicId;
use Database\Factories\BilliardTableFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[UseFactory(BilliardTableFactory::class)]
class BilliardTable extends Model
{
    use BelongsToTenant, HasFactory, HasPublicId;

    protected $table = 'billiard_tables';

    protected $fillable = ['branch_id', 'number', 'name', 'is_active', 'pricing_plan_id'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'is_active' => 'boolean',
            'device_channel' => 'integer',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<PricingPlan, $this> */
    public function pricingPlan(): BelongsTo
    {
        return $this->belongsTo(PricingPlan::class);
    }

    /** ESP32 whose relay channel `device_channel` switches this table's lamp. @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }
}
