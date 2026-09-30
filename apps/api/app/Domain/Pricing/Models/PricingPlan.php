<?php

namespace App\Domain\Pricing\Models;

use App\Domain\Pricing\Enums\PricingType;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Support\Concerns\HasPublicId;
use Database\Factories\PricingPlanFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Price configuration; `type` selects the PriceCalculator strategy (spec §6: extensible pricing). */
#[UseFactory(PricingPlanFactory::class)]
class PricingPlan extends Model
{
    use BelongsToTenant, HasFactory, HasPublicId;

    protected $fillable = ['branch_id', 'name', 'type', 'price_per_hour', 'rounding_step', 'allowed_durations', 'rules', 'is_active'];

    protected $attributes = [
        'type' => 'HOURLY',
        'rounding_step' => 1000,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'type' => PricingType::class,
            'price_per_hour' => 'integer',
            'rounding_step' => 'integer',
            'allowed_durations' => 'array',
            'rules' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
