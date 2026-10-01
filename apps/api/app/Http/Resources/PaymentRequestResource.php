<?php

namespace App\Http\Resources;

use App\Domain\Subscriptions\Models\SubscriptionPaymentRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SubscriptionPaymentRequest — the receipt file is served only through its own authorized endpoint. */
final class PaymentRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'status' => $this->status->value,
            'months' => $this->months,
            'branchCount' => $this->branch_count,
            'pricePerBranch' => $this->price_per_branch,
            'amount' => $this->amount,
            'note' => $this->note,
            'rejectReason' => $this->reject_reason,
            'createdAt' => $this->created_at->toIso8601ZuluString(),
            'reviewedAt' => $this->reviewed_at?->toIso8601ZuluString(),
            'tenant' => $this->whenLoaded('tenant', fn () => ['id' => $this->tenant->public_id, 'name' => $this->tenant->name]),
        ];
    }
}
