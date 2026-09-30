<?php

namespace App\Http\Resources;

use App\Domain\Sessions\Enums\SessionStatus;
use App\Domain\Sessions\Models\GameSession;
use App\Domain\Sessions\Models\SessionEvent;
use App\Domain\Sessions\Services\TableStatusResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin GameSession */
final class SessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $effective = $this->status;
        if ($this->status->isOccupying() && ! TableStatusResolver::stillOccupying($this->resource, CarbonImmutable::now())) {
            $effective = match ($this->status) {
                SessionStatus::ACTIVE, SessionStatus::COMPLETING => SessionStatus::COMPLETED,
                SessionStatus::RESERVED => SessionStatus::CANCELLED,
                default => $this->status,
            };
        }

        return [
            'id' => $this->public_id,
            'status' => $this->status->value,
            'effectiveStatus' => $effective->value,
            'branch' => $this->whenLoaded('branch', fn () => ['id' => $this->branch->public_id, 'name' => $this->branch->name]),
            'table' => $this->whenLoaded('table', fn () => ['id' => $this->table->public_id, 'number' => $this->table->number, 'name' => $this->table->name]),
            'device' => $this->whenLoaded('device', fn () => $this->device ? ['code' => $this->device->device_code, 'online' => $this->device->isOnline()] : null),
            'durationMinutes' => $this->duration_minutes,
            'startAt' => $this->start_at?->toIso8601ZuluString(),
            'endAt' => $this->end_at?->toIso8601ZuluString(),
            'endedAt' => $this->ended_at?->toIso8601ZuluString(),
            'endedEarly' => $this->ended_early,
            'pricePerHour' => $this->price_per_hour_snapshot,
            'amount' => $this->amount,
            'paymentStatus' => $this->payment_status->value,
            'paymentMarkedAt' => $this->payment_marked_at?->toIso8601ZuluString(),
            'failureReason' => $this->failure_reason,
            'photo' => $this->whenLoaded('photo', fn () => $this->photo && ! $this->photo->isDeleted()
                ? ['id' => $this->photo->public_id, 'createdAt' => $this->photo->created_at->toIso8601ZuluString()]
                : null),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn (SessionEvent $e) => [
                'from' => $e->from_status?->value,
                'to' => $e->to_status->value,
                'actorType' => $e->actor_type->value,
                'reason' => $e->reason,
                'at' => $e->created_at->toIso8601ZuluString(),
            ])),
            'createdAt' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
