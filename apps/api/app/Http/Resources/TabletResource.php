<?php

namespace App\Http\Resources;

use App\Domain\Tablets\Models\Tablet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Tablet */
final class TabletResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $online = $this->last_seen_at !== null && $this->last_seen_at->greaterThan(now()->subSeconds(90));

        return [
            'id' => $this->public_id,
            'code' => $this->device_code,
            'name' => $this->name,
            'status' => $this->status->value,
            'online' => $online,
            'lastSeenAt' => $this->last_seen_at?->toIso8601ZuluString(),
            'appVersion' => $this->app_version,
            'deviceModel' => $this->device_model,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch ? ['id' => $this->branch->public_id, 'name' => $this->branch->name] : null),
            'pairedAt' => $this->paired_at?->toIso8601ZuluString(),
        ];
    }
}
