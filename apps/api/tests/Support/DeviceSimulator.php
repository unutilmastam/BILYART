<?php

namespace Tests\Support;

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Test-only ESP32 stand-in speaking the real HTTP protocol (DEVICE_PROTOCOL.md).
 * It keeps a tiny local model of the light like the firmware does, so tests
 * can check "light ON/OFF" and local end-of-session behaviour without network.
 */
final class DeviceSimulator
{
    public string $hardwareId;

    public ?string $deviceCode = null;

    public ?string $pairingCode = null;

    public ?string $pollToken = null;

    public ?string $token = null;

    /** @var array<int, array{light: string, sessionId: ?string, endAt: ?int}> relay channel → local state */
    public array $channels = [];

    /** @var list<string> */
    public array $applied = [];

    public function __construct(private readonly TestCase $test, ?string $hardwareId = null, public readonly int $channelCount = 4)
    {
        $this->hardwareId = $hardwareId ?? strtoupper(bin2hex(random_bytes(6)));
        for ($ch = 1; $ch <= $channelCount; $ch++) {
            $this->channels[$ch] = ['light' => 'OFF', 'sessionId' => null, 'endAt' => null];
        }
    }

    /** Light of a relay channel ("ON" / "OFF"). */
    public function light(int $channel = 1): string
    {
        return $this->channels[$channel]['light'];
    }

    public function sessionOn(int $channel = 1): ?string
    {
        return $this->channels[$channel]['sessionId'];
    }

    public function register(string $secret = 'test-registration-secret-123'): TestResponse
    {
        $res = $this->test->postJson('/device/v1/register', [
            'hardwareId' => $this->hardwareId, 'firmwareVersion' => '1.0.0', 'registrationSecret' => $secret, 'channelCount' => $this->channelCount,
        ]);
        if ($res->status() === 200) {
            $this->deviceCode = $res->json('deviceCode');
            $this->pairingCode = $res->json('pairingCode');
            $this->pollToken = $res->json('pollToken');
        }

        return $res;
    }

    public function pairingStatus(): TestResponse
    {
        $res = $this->test->getJson('/device/v1/pairing-status', ['Authorization' => 'PollToken '.$this->pollToken]);
        if ($res->json('token')) {
            $this->token = $res->json('token');
        }

        return $res;
    }

    public function auth(): array
    {
        return ['Authorization' => "Device {$this->deviceCode}.{$this->token}"];
    }

    public function poll(array $extra = []): TestResponse
    {
        $this->tick();

        return $this->test->postJson('/device/v1/poll', array_merge([
            'ts' => now()->getTimestamp(), 'fw' => '1.0.0', 'channels' => $this->report(), 'rssi' => -60, 'uptime' => 100,
        ], $extra), $this->auth());
    }

    /** @return list<array{channel: int, state: string, sessionId: ?string, endAt: ?int}> */
    public function report(): array
    {
        $out = [];
        foreach ($this->channels as $ch => $c) {
            $out[] = ['channel' => $ch, 'state' => $c['light'], 'sessionId' => $c['sessionId'], 'endAt' => $c['endAt']];
        }

        return $out;
    }

    /** Poll, apply commands like the firmware, ACK them. Returns the commands received. */
    public function pollAndApply(): array
    {
        $commands = $this->poll()->assertOk()->json('commands');
        $acks = [];
        foreach ($commands as $c) {
            $result = $this->apply($c);
            $ch = $c['payload']['channel'] ?? null;
            $acks[] = ['commandId' => $c['commandId'], 'result' => $result, 'error' => null, 'channel' => $ch, 'state' => $ch !== null && isset($this->channels[$ch]) ? $this->channels[$ch]['light'] : null];
        }
        if ($acks !== []) {
            $this->ack($acks)->assertOk();
        }

        return $commands;
    }

    public function ack(array $acks): TestResponse
    {
        return $this->test->postJson('/device/v1/ack', ['acks' => $acks], $this->auth() + ['Idempotency-Key' => (string) Str::uuid()]);
    }

    public function state(): TestResponse
    {
        return $this->test->getJson('/device/v1/state', $this->auth());
    }

    /** Local timer: light OFF at endAt even with no network (spec §14, §35). */
    public function tick(): void
    {
        foreach ($this->channels as $ch => $c) {
            if ($c['light'] !== 'OFF' && $c['endAt'] !== null && now()->getTimestamp() >= $c['endAt']) {
                $this->channels[$ch] = ['light' => 'OFF', 'sessionId' => null, 'endAt' => null];
            }
        }
    }

    private function apply(array $c): string
    {
        if (in_array($c['commandId'], $this->applied, true) || $c['expiresAt'] < now()->getTimestamp()) {
            return 'IGNORED';
        }
        $ch = $c['payload']['channel'] ?? null;
        if (in_array($c['type'], ['START_SESSION', 'STOP_SESSION', 'WARNING'], true) && ! isset($this->channels[$ch])) {
            return 'ERROR'; // the firmware rejects an unknown relay channel
        }
        $this->applied[] = $c['commandId'];
        switch ($c['type']) {
            case 'START_SESSION':
                $this->channels[$ch] = ['light' => 'ON', 'sessionId' => $c['payload']['sessionId'], 'endAt' => $c['payload']['endAt']];
                break;
            case 'STOP_SESSION':
                if ($this->channels[$ch]['sessionId'] === $c['payload']['sessionId']) {
                    $this->channels[$ch] = ['light' => 'OFF', 'sessionId' => null, 'endAt' => null];
                }
                break;
        }

        return 'OK';
    }
}
