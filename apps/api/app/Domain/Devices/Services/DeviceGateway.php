<?php

namespace App\Domain\Devices\Services;

use App\Domain\Audit\Enums\ActorType;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Devices\Enums\CommandStatus;
use App\Domain\Devices\Enums\CommandType;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\DeviceCommand;
use App\Domain\Devices\Models\DeviceHeartbeat;
use App\Domain\Sessions\Enums\SessionStatus;
use App\Domain\Sessions\Models\GameSession;
use App\Domain\Sessions\Services\SessionService;
use App\Domain\Tables\Models\BilliardTable;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Server side of the HTTPS-polling transport (DEVICE_PROTOCOL.md §3).
 * /poll must stay cheap (≈17 req/s for 50 devices): a handful of indexed queries.
 * Retry policy: a SENT command without ACK is re-sent after 6 s, max 3 attempts,
 * then FAILED; anything past expiresAt is EXPIRED and never delivered.
 */
final class DeviceGateway
{
    public const RESEND_AFTER_SEC = 6;

    public const MAX_ATTEMPTS = 3;

    /** Raw heartbeat rows are kept sparse: on state change or every 60 s. */
    public const HEARTBEAT_ROW_EVERY_SEC = 60;

    public const MAX_SESSION_SEC = 43200;

    public function __construct(
        private readonly SessionService $sessions,
        private readonly TenantSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{ts: int, fw: string, channels: list<array{channel: int, state: string, sessionId?: ?string, endAt?: ?int}>, rssi?: ?int, uptime?: ?int, bootReason?: ?string}  $beat
     */
    public function poll(Device $device, array $beat, string $ip): array
    {
        $now = CarbonImmutable::now();
        $channels = collect($beat['channels'])->sortBy('channel')->values()
            ->map(fn (array $c) => ['channel' => (int) $c['channel'], 'state' => $c['state'], 'sessionId' => $c['sessionId'] ?? null, 'endAt' => $c['endAt'] ?? null])->all();
        // One letter per channel (N = on, W = warning, F = off), e.g. "NFWF" — fits the heartbeat row.
        $summary = implode('', array_map(fn (array $c) => ['ON' => 'N', 'WARNING' => 'W'][$c['state']] ?? 'F', $channels));
        $previous = $device->last_state['summary'] ?? null;
        $lastSeen = $device->last_seen_at;

        $device->forceFill([
            'last_seen_at' => $now,
            'last_ip' => $ip,
            'firmware_version' => $beat['fw'],
            'last_state' => ['summary' => $summary, 'channels' => $channels] + array_intersect_key($beat, array_flip(['rssi', 'uptime', 'bootReason'])),
        ])->save();

        if ($previous !== $summary || $lastSeen === null || $lastSeen->lessThan($now->subSeconds(self::HEARTBEAT_ROW_EVERY_SEC))) {
            $hb = new DeviceHeartbeat([
                'device_id' => $device->id, 'received_at' => $now, 'state' => substr($summary, 0, 8),
                'session_public_id' => collect($channels)->pluck('sessionId')->filter()->first(), 'rssi' => $beat['rssi'] ?? null,
                'uptime' => $beat['uptime'] ?? null, 'fw' => $beat['fw'], 'boot_reason' => $beat['bootReason'] ?? null,
            ]);
            $hb->tenant_id = $device->tenant_id;
            $hb->save();
        }

        return [
            'serverTime' => $now->getTimestamp(),
            'commands' => $this->deliverable($device, $now),
            'pollIntervalSec' => (int) config('devices.poll_interval_sec', 3),
        ];
    }

    /** @param list<array{commandId: string, result: string, error?: ?string, state?: ?string}> $acks */
    public function ack(Device $device, array $acks): array
    {
        $accepted = [];
        foreach ($acks as $ack) {
            $done = DB::transaction(function () use ($device, $ack): bool {
                /** @var DeviceCommand|null $command */
                $command = DeviceCommand::query()->where('device_id', $device->id)->where('public_id', $ack['commandId'])->lockForUpdate()->first();
                if ($command === null) {
                    return false;
                }
                if (in_array($command->status, [CommandStatus::ACKNOWLEDGED, CommandStatus::FAILED], true)) {
                    return true; // duplicate ACK
                }
                $ok = $ack['result'] !== 'ERROR';
                $command->forceFill([
                    'status' => $ok ? CommandStatus::ACKNOWLEDGED : CommandStatus::FAILED,
                    'acked_at' => now(),
                    'result' => $ack['result'],
                    'last_error' => $ack['error'] ?? null,
                ])->save();
                $this->applyAck($device, $command, $ok);

                return true;
            });
            if ($done) {
                $accepted[] = $ack['commandId'];
            }
        }

        return ['serverTime' => now()->getTimestamp(), 'accepted' => $accepted];
    }

    /** Authoritative state after boot/reconnect (spec §35, DEVICE_PROTOCOL.md §6): the running session of every wired channel. */
    public function state(Device $device): array
    {
        $now = CarbonImmutable::now();
        $channelOf = BilliardTable::query()->where('device_id', $device->id)->pluck('device_channel', 'id');
        $sessions = GameSession::query()
            ->whereIn('table_id', $channelOf->keys()->all())
            ->where('device_id', $device->id)
            ->whereIn('status', [SessionStatus::STARTING->value, SessionStatus::ACTIVE->value])
            ->where('end_at', '>', $now)
            ->orderByDesc('id')->get()
            ->unique('table_id');

        return [
            'serverTime' => $now->getTimestamp(),
            'sessions' => $sessions->map(fn (GameSession $s) => [
                'channel' => (int) ($s->device_channel ?? $channelOf[$s->table_id]),
                'sessionId' => $s->public_id,
                'startAt' => $s->start_at->getTimestamp(),
                'endAt' => $s->end_at->getTimestamp(),
                'status' => $s->status->value,
            ])->sortBy('channel')->values()->all(),
            'config' => $this->config($device),
        ];
    }

    public function config(Device $device): array
    {
        $settings = $this->settings->for(Tenant::query()->findOrFail($device->tenant_id));

        return [
            'pollIntervalSec' => (int) config('devices.poll_interval_sec', 3),
            'maxSessionSec' => self::MAX_SESSION_SEC,
            'warnBeforeSec' => (int) $settings['warn_before_minutes'] * 60,
            'flashCount' => 3,
        ];
    }

    /** Commands ready for this poll; marks them SENT. */
    private function deliverable(Device $device, CarbonImmutable $now): array
    {
        $candidates = DeviceCommand::query()
            ->where('device_id', $device->id)
            ->whereIn('status', [CommandStatus::PENDING->value, CommandStatus::SENT->value])
            ->orderBy('id')->limit(10)->get();

        $out = [];
        foreach ($candidates as $command) {
            if ($command->expires_at->lessThanOrEqualTo($now)) {
                $command->forceFill(['status' => CommandStatus::EXPIRED])->save();

                continue;
            }
            if ($command->status === CommandStatus::SENT) {
                if ($command->sent_at !== null && $command->sent_at->greaterThan($now->subSeconds(self::RESEND_AFTER_SEC))) {
                    continue; // waiting for ACK
                }
                if ($command->attempts >= self::MAX_ATTEMPTS) {
                    $command->forceFill(['status' => CommandStatus::FAILED, 'last_error' => 'no ACK after '.self::MAX_ATTEMPTS.' attempts'])->save();

                    continue;
                }
            }
            $command->forceFill(['status' => CommandStatus::SENT, 'sent_at' => $now, 'attempts' => $command->attempts + 1])->save();
            $out[] = [
                'commandId' => $command->public_id,
                'type' => $command->type->value,
                'expiresAt' => $command->expires_at->getTimestamp(),
                'payload' => (object) $command->payload,
            ];
        }

        return $out;
    }

    private function applyAck(Device $device, DeviceCommand $command, bool $ok): void
    {
        if ($command->session_id === null) {
            return;
        }
        $session = GameSession::query()->find($command->session_id);
        if ($session === null) {
            return;
        }
        match ($command->type) {
            CommandType::START_SESSION => $ok
                ? $this->sessions->confirmStarted($session, $device->id)
                : $this->sessions->failStart($session, 'DEVICE_ERROR'),
            CommandType::STOP_SESSION => $session->status === SessionStatus::COMPLETING
                ? $this->sessions->complete($session, ActorType::DEVICE, $device->id, 'STOP_ACK')
                : null,
            default => null,
        };
    }
}
