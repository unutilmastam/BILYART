<?php

namespace App\Http\Resources;

use App\Domain\Tables\Models\BilliardTable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BilliardTable */
final class TableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'branchId' => $this->whenLoaded('branch', fn () => $this->branch->public_id),
            'number' => $this->number,
            'name' => $this->name,
            'isActive' => $this->is_active,
            'pricingPlan' => $this->whenLoaded('pricingPlan', fn () => $this->pricingPlan ? [
                'id' => $this->pricingPlan->public_id,
                'name' => $this->pricingPlan->name,
                'pricePerHour' => $this->pricingPlan->price_per_hour,
            ] : null),
            'device' => $this->whenLoaded('device', fn () => $this->device ? [
                'id' => $this->device->public_id,
                'code' => $this->device->device_code,
                'channel' => $this->device_channel,
                'online' => $this->device->isOnline(),
                'lastSeenAt' => $this->device->last_seen_at?->toIso8601ZuluString(),
            ] : null),
        ];
    }
}
