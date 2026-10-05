<?php

namespace App\Http\Resources;

use App\Domain\Branches\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Branch */
final class BranchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'address' => $this->address,
            'phone' => $this->phone,
            'timezone' => $this->timezone,
            'isActive' => $this->is_active,
            'reportTime' => substr((string) $this->report_time, 0, 5),
            'paymentMode' => $this->payment_mode->value,
        ];
    }
}
