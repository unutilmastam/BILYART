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
            'rssi' => $this->last_state['rssi'] ?? null,
            'channelCount' => $this->channel_count,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch ? ['id' => $this->branch->public_id, 'name' => $this->branch->name] : null),
            // One row per relay channel: which table it drives and the lamp state the device last reported.
            'channels' => $this->whenLoaded('tables', function () {
                $reported = collect($this->last_state['channels'] ?? [])->keyBy('channel');
                $byChannel = $this->tables->keyBy('device_channel');

                return collect(range(1, $this->channel_count))->map(fn (int $ch) => [
                    'channel' => $ch,
                    'table' => ($t = $byChannel->get($ch)) ? ['id' => $t->public_id, 'number' => $t->number, 'name' => $t->name] : null,
                    'state' => $reported->get($ch)['state'] ?? null,
                ])->all();
            }),
            'pairedAt' => $this->paired_at?->toIso8601ZuluString(),
        ];
    }
}
