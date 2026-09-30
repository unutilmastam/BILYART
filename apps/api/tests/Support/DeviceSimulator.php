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

    public string $light = 'OFF';

    public ?string $sessionId = null;

    public ?int $endAt = null;

    /** @var list<string> */
    public array $applied = [];

    public function __construct(private readonly TestCase $test, ?string $hardwareId = null)
    {
        $this->hardwareId = $hardwareId ?? strtoupper(bin2hex(random_bytes(6)));
    }

    public function register(string $secret = 'test-registration-secret-123'): TestResponse
    {
        $res = $this->test->postJson('/device/v1/register', [
            'hardwareId' => $this->hardwareId, 'firmwareVersion' => '1.0.0', 'registrationSecret' => $secret,
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
            'ts' => now()->getTimestamp(), 'fw' => '1.0.0', 'state' => $this->light, 'sessionId' => $this->sessionId,
            'endAt' => $this->endAt, 'rssi' => -60, 'uptime' => 100,
        ], $extra), $this->auth());
    }

    /** Poll, apply commands like the firmware, ACK them. Returns the commands received. */
    public function pollAndApply(): array
    {
        $commands = $this->poll()->assertOk()->json('commands');
        $acks = [];
        foreach ($commands as $c) {
            $result = $this->apply($c);
            $acks[] = ['commandId' => $c['commandId'], 'result' => $result, 'error' => null, 'state' => $this->light];
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
        if ($this->light !== 'OFF' && $this->endAt !== null && now()->getTimestamp() >= $this->endAt) {
            $this->light = 'OFF';
            $this->sessionId = null;
            $this->endAt = null;
        }
    }

    private function apply(array $c): string
    {
        if (in_array($c['commandId'], $this->applied, true) || $c['expiresAt'] < now()->getTimestamp()) {
            return 'IGNORED';
        }
        $this->applied[] = $c['commandId'];
        switch ($c['type']) {
            case 'START_SESSION':
                $this->sessionId = $c['payload']['sessionId'];
                $this->endAt = $c['payload']['endAt'];
                $this->light = 'ON';
                break;
            case 'STOP_SESSION':
                if ($this->sessionId === $c['payload']['sessionId']) {
                    $this->light = 'OFF';
                    $this->sessionId = null;
                    $this->endAt = null;
                }
                break;
        }

        return 'OK';
    }
}
