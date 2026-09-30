<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Devices\Enums\CommandStatus;
use App\Domain\Devices\Enums\CommandType;
use App\Domain\Devices\Enums\DeviceStatus;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\DeviceCommand;
use App\Domain\Devices\Services\DeviceCommandBus;
use App\Domain\Platform\Models\FirmwareRelease;
use App\Domain\Sessions\Enums\SessionStatus;
use App\Domain\Sessions\Models\GameSession;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Super Admin → firmware releases for OTA (spec §55). The sha256 is computed here, never trusted from input. */
final class FirmwareController extends Controller
{
    public function index(): array
    {
        return ['data' => FirmwareRelease::query()->orderByDesc('id')->get()->map(fn (FirmwareRelease $r) => $this->present($r))];
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'version' => ['required', 'string', 'max:32', 'regex:/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', 'unique:firmware_releases,version'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'file' => ['required', 'file', 'max:4096'],
        ]);
        $bytes = (string) file_get_contents($request->file('file')->getRealPath());
        // ESP32 app images start with magic byte 0xE9.
        if (strlen($bytes) < 1024 || $bytes[0] !== "\xE9") {
            throw new ApiException(ErrorCode::VALIDATION_FAILED, [], ['fields' => ['file' => [__('firmware.not_image')]]]);
        }
        // The build embeds "BLYFWVER:<version>\n"; the device reports that version after the update.
        if (! preg_match('/BLYFWVER:([0-9A-Za-z.+-]{1,32})\n/', $bytes, $m)) {
            throw new ApiException(ErrorCode::VALIDATION_FAILED, [], ['fields' => ['file' => [__('firmware.no_marker')]]]);
        }
        if ($m[1] !== $data['version']) {
            throw new ApiException(ErrorCode::VALIDATION_FAILED, [], ['fields' => ['version' => [__('firmware.version_mismatch', ['version' => $m[1]])]]]);
        }
        $path = 'firmware/'.strtoupper((string) Str::ulid()).'.bin';
        Storage::disk('local')->put($path, $bytes);

        $release = new FirmwareRelease(['version' => $data['version'], 'notes' => $data['notes'] ?? null]);
        $release->forceFill(['sha256' => hash('sha256', $bytes), 'file_path' => $path, 'size' => strlen($bytes), 'is_published' => false, 'created_by' => $request->user()->id])->save();
        $audit->log('firmware.uploaded', $release, ['version' => $release->version, 'sha256' => $release->sha256], ['tenant_id' => null]);

        return response()->json(['data' => $this->present($release)], 201);
    }

    public function publish(Request $request, FirmwareRelease $release, AuditLogger $audit): array
    {
        $release->forceFill(['is_published' => true, 'published_at' => now()])->save();
        $audit->log('firmware.published', $release, ['version' => $release->version], ['tenant_id' => null]);

        return ['data' => $this->present($release)];
    }

    /**
     * Queues an OTA command for every paired device that runs another version and has no game in
     * progress (the firmware refuses OTA during a session anyway). Safe to repeat: devices that
     * already have this OTA pending are skipped.
     */
    public function rollout(Request $request, FirmwareRelease $release, DeviceCommandBus $bus, TenantContext $context, AuditLogger $audit): array
    {
        if (! $release->is_published) {
            throw new ApiException(ErrorCode::INVALID_STATE_TRANSITION);
        }

        $result = $context->runAsSystem(function () use ($release, $bus): array {
            $queued = $busy = $current = 0;
            Device::query()->where('status', DeviceStatus::PAIRED->value)->orderBy('id')->each(function (Device $device) use ($release, $bus, &$queued, &$busy, &$current): void {
                if ($device->firmware_version === $release->version) {
                    $current++;

                    return;
                }
                $playing = GameSession::query()->where('table_id', $device->table_id)->whereIn('status', SessionStatus::occupying())->exists();
                $pending = DeviceCommand::query()->where('device_id', $device->id)->where('type', CommandType::OTA->value)
                    ->whereIn('status', [CommandStatus::PENDING->value, CommandStatus::SENT->value])->exists();
                if ($playing || $pending) {
                    $busy++;

                    return;
                }
                $bus->queue($device, CommandType::OTA, ['version' => $release->version, 'sha256' => $release->sha256, 'size' => $release->size], null, 3600);
                $queued++;
            });

            return ['queued' => $queued, 'skippedBusy' => $busy, 'alreadyCurrent' => $current];
        });
        $audit->log('firmware.rollout', $release, ['version' => $release->version] + $result, ['tenant_id' => null]);

        return ['data' => $result];
    }

    private function present(FirmwareRelease $r): array
    {
        return [
            'id' => $r->public_id, 'version' => $r->version, 'sha256' => $r->sha256, 'size' => $r->size,
            'notes' => $r->notes, 'isPublished' => $r->is_published, 'publishedAt' => $r->published_at?->toIso8601ZuluString(),
        ];
    }
}
