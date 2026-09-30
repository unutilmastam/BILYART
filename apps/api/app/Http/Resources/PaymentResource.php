<?php

namespace App\Http\Resources;

use App\Domain\Subscriptions\Models\SubscriptionPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SubscriptionPayment */
final class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'tenant' => $this->whenLoaded('tenant', fn () => ['id' => $this->tenant->public_id, 'name' => $this->tenant->name]),
            'amount' => $this->amount,
            'currency' => $this->currency,
            'method' => $this->method->value,
            'note' => $this->note,
            'paidAt' => $this->paid_at->toIso8601ZuluString(),
            'createdAt' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
