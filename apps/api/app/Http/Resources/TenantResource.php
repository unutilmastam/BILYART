<?php

namespace App\Http\Resources;

use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Tenant */
final class TenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'contactName' => $this->contact_name,
            'contactPhone' => $this->contact_phone,
            'timezone' => $this->timezone,
            'statusFlag' => $this->status_flag->value,
            'subscription' => [
                'status' => $this->subscriptionStatus()->value,
                'expiresAt' => $this->subscription_expires_at?->toIso8601ZuluString(),
                'daysLeft' => $this->daysLeft(),
            ],
            'limits' => [
                'branchLimit' => $this->branch_limit,
                'tableLimit' => $this->table_limit,
                'deviceLimit' => $this->device_limit,
                'userLimit' => $this->user_limit,
            ],
            'usage' => $this->whenHas('branches_count', fn () => [
                'branches' => (int) $this->branches_count,
                'tables' => (int) ($this->tables_count ?? 0),
                'devices' => (int) ($this->devices_count ?? 0),
                'users' => (int) ($this->users_count ?? 0),
            ]),
            'createdAt' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
