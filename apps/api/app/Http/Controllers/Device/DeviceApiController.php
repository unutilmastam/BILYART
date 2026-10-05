<?php

namespace App\Http\Controllers\Device;

use App\Domain\Devices\Enums\DeviceKind;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Services\DeviceGateway;
use App\Domain\Devices\Services\DeviceRegistry;
use App\Domain\Platform\Models\FirmwareRelease;
use App\Http\Controllers\Controller;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** /device/v1 — protocol: packages/protocol/schemas/device.*.schema.json */
final class DeviceApiController extends Controller
{
    public function __construct(
        private readonly DeviceRegistry $registry,
        private readonly DeviceGateway $gateway,
    ) {}

    public function register(Request $request): array
    {
        $data = $request->validate([
            'hardwareId' => ['required', 'string', 'regex:/^[0-9A-Fa-f]{12}$/'],
            'firmwareVersion' => ['required', 'string', 'max:32', 'regex:/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/'],
            'registrationSecret' => ['required', 'string', 'min:16', 'max:128'],
            'channelCount' => ['sometimes', 'integer', 'between:1,'.Device::MAX_CHANNELS],
            'kind' => ['sometimes', 'in:LIGHT,CASH'],
        ]);
        $expected = (string) config('devices.registration_secret');
        // Not an ownership proof — only keeps random internet clients from spamming registrations.
        if ($expected === '' || ! hash_equals($expected, $data['registrationSecret'])) {
            throw ApiException::of(ErrorCode::DEVICE_UNAUTHORIZED);
        }

        return $this->registry->register($data['hardwareId'], $data['firmwareVersion'], (string) $request->ip(), (int) ($data['channelCount'] ?? 1), DeviceKind::from($data['kind'] ?? 'LIGHT'));
    }

    public function pairingStatus(Request $request): array
    {
        $header = (string) $request->header('Authorization', '');
        if (! preg_match('/^PollToken ([A-Za-z0-9_-]{32,128})$/', $header, $m)) {
            throw ApiException::of(ErrorCode::DEVICE_UNAUTHORIZED);
        }

        return $this->registry->pairingStatus($m[1]);
    }

    public function poll(Request $request): array
    {
        $data = $request->validate([
            'ts' => ['required', 'integer', 'min:0'],
            'fw' => ['required', 'string', 'max:32'],
            'channels' => ['required', 'array', 'min:1', 'max:'.Device::MAX_CHANNELS],
            'channels.*.channel' => ['required', 'integer', 'distinct', 'between:1,'.Device::MAX_CHANNELS],
            'channels.*.state' => ['required', 'in:ON,OFF,WARNING'],
            'channels.*.sessionId' => ['nullable', 'string', 'size:26'],
            'channels.*.endAt' => ['nullable', 'integer', 'min:0'],
            'rssi' => ['nullable', 'integer', 'between:-127,0'],
            'uptime' => ['nullable', 'integer', 'min:0'],
            'bootReason' => ['nullable', 'string', 'max:32'],
            'lastAppliedCommandId' => ['nullable', 'string', 'size:26'],
        ]);

        return $this->gateway->poll($this->device($request), $data, (string) $request->ip());
    }

    public function ack(Request $request): array
    {
        $data = $request->validate([
            'acks' => ['required', 'array', 'min:1', 'max:20'],
            'acks.*.commandId' => ['required', 'string', 'size:26'],
            'acks.*.result' => ['required', 'in:OK,ERROR,IGNORED'],
            'acks.*.error' => ['nullable', 'string', 'max:200'],
            'acks.*.state' => ['nullable', 'in:ON,OFF,WARNING'],
            'acks.*.channel' => ['nullable', 'integer', 'between:1,'.Device::MAX_CHANNELS],
        ]);

        return $this->gateway->ack($this->device($request), $data['acks']);
    }

    public function state(Request $request): array
    {
        return $this->gateway->state($this->device($request));
    }

    /** OTA download (spec §55): authenticated, only published releases, sha256 known in advance. */
    public function firmware(Request $request, string $version): StreamedResponse
    {
        /** @var FirmwareRelease|null $release */
        $release = FirmwareRelease::query()->where('version', $version)->where('is_published', true)->first();
        if ($release === null || ! Storage::disk('local')->exists($release->file_path)) {
            throw ApiException::of(ErrorCode::NOT_FOUND);
        }

        return Storage::disk('local')->download($release->file_path, "firmware-{$release->version}.bin", [
            'Content-Type' => 'application/octet-stream',
            'X-Firmware-Sha256' => $release->sha256,
        ]);
    }

    /** The lamp endpoints (poll/ack/state) are for relay controllers only; the bill acceptor has /cash/*. */
    private function device(Request $request): Device
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');
        if ($device->isCash()) {
            throw ApiException::of(ErrorCode::DEVICE_KIND_MISMATCH);
        }

        return $device;
    }
}
