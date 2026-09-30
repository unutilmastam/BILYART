<?php

namespace App\Http\Resources;

use App\Domain\Pricing\Models\PricingPlan;
use App\Domain\Pricing\Services\PriceCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PricingPlan */
final class PricingPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'type' => $this->type->value,
            'branchId' => $this->whenLoaded('branch', fn () => $this->branch?->public_id),
            'pricePerHour' => $this->price_per_hour,
            'roundingStep' => $this->rounding_step,
            'allowedDurations' => array_values(array_map('intval', $this->allowed_durations ?? [])),
            'quotes' => app(PriceCalculator::class)->quotes($this->resource),
            'isActive' => $this->is_active,
        ];
    }
}
