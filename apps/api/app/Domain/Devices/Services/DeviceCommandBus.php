<?php

namespace App\Domain\Devices\Services;

use App\Domain\Devices\Enums\CommandStatus;
use App\Domain\Devices\Enums\CommandType;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\DeviceCommand;
use App\Domain\Sessions\Models\GameSession;

/**
 * Queues authenticated commands for a device (spec §34). v1 transport is HTTPS
 * polling: the device fetches PENDING/SENT commands on /device/v1/poll
 * (DEVICE_PROTOCOL.md). Session commands carry the relay channel the session was
 * started on. A newer START/STOP for the same device channel supersedes
 * older undelivered ones, so a late command can never flip the light.
 */
final class DeviceCommandBus
{
    /** Seconds a START may wait for delivery + ACK before the session fails (ARCHITECTURE §2.8). */
    public const START_ACK_TIMEOUT_SEC = 20;

    public function startSession(Device $device, GameSession $session, int $warnBeforeSec, int $flashCount): DeviceCommand
    {
        return $this->queue($device, CommandType::START_SESSION, [
            'channel' => $session->device_channel,
            'sessionId' => $session->public_id,
            'startAt' => $session->start_at->getTimestamp(),
            'endAt' => $session->end_at->getTimestamp(),
            'warnBeforeSec' => $warnBeforeSec,
            'flashCount' => $flashCount,
        ], $session, self::START_ACK_TIMEOUT_SEC + 10);
    }

    public function stopSession(Device $device, GameSession $session): DeviceCommand
    {
        // STOP stays valid until the session would have ended anyway (+ margin): the device ignores it afterwards.
        $ttl = max(60, ($session->end_at?->getTimestamp() ?? now()->getTimestamp()) - now()->getTimestamp() + 300);

        return $this->queue($device, CommandType::STOP_SESSION, ['channel' => $session->device_channel, 'sessionId' => $session->public_id], $session, $ttl);
    }

    public function queue(Device $device, CommandType $type, array $payload, ?GameSession $session, int $ttlSec): DeviceCommand
    {
        if (in_array($type, [CommandType::START_SESSION, CommandType::STOP_SESSION], true) && $session !== null) {
            DeviceCommand::query()
                ->where('device_id', $device->id)
                ->where('channel', $session->device_channel) // other tables on the same device are independent
                ->whereIn('type', [CommandType::START_SESSION->value, CommandType::STOP_SESSION->value])
                ->where('session_id', '!=', $session->id)
                ->whereIn('status', [CommandStatus::PENDING->value, CommandStatus::SENT->value])
                ->update(['status' => CommandStatus::EXPIRED->value, 'last_error' => 'superseded']);
        }

        if ($type === CommandType::STOP_SESSION && $session !== null) {
            // A STOP cancels this session's undelivered START: a late START must never turn the light on.
            DeviceCommand::query()
                ->where('device_id', $device->id)->where('session_id', $session->id)
                ->where('type', CommandType::START_SESSION->value)
                ->whereIn('status', [CommandStatus::PENDING->value, CommandStatus::SENT->value])
                ->update(['status' => CommandStatus::EXPIRED->value, 'last_error' => 'cancelled by STOP']);
        }

        $command = new DeviceCommand([
            'device_id' => $device->id,
            'session_id' => $session?->id,
            'channel' => $session?->device_channel,
            'type' => $type,
            'payload' => $payload,
            'expires_at' => now()->addSeconds($ttlSec),
        ]);
        $command->tenant_id = $device->tenant_id;
        $command->save();

        return $command;
    }
}
