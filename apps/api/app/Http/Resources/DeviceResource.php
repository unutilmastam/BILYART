<?php

namespace App\Http\Resources;

use App\Domain\Devices\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Device — online is derived from the real last heartbeat (spec §62: never faked). */
final class DeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'code' => $this->device_code,
            'status' => $this->status->value,
            'online' => $this->isOnline(),
            'lastSeenAt' => $this->last_seen_at?->toIso8601ZuluString(),
            'firmwareVersion' => $this->firmware_version,
            'state' => $this->last_state['state'] ?? null,
            'rssi' => $this->last_state['rssi'] ?? null,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch ? ['id' => $this->branch->public_id, 'name' => $this->branch->name] : null),
            'table' => $this->whenLoaded('table', fn () => $this->table ? ['id' => $this->table->public_id, 'number' => $this->table->number, 'name' => $this->table->name] : null),
            'pairedAt' => $this->paired_at?->toIso8601ZuluString(),
        ];
    }
}
