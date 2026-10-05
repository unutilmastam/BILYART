<?php

namespace App\Http\Controllers\Device;

use App\Domain\Cash\Services\CashPaymentService;
use App\Domain\Devices\Models\Device;
use App\Domain\Sessions\Models\GameSession;
use App\Http\Controllers\Controller;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * /device/v1/cash — the branch's bill acceptor box (DEVICE_PROTOCOL.md §8).
 * The box opens its acceptor only while `accept` is true and the server is reachable.
 */
final class CashDeviceController extends Controller
{
    public const POLL_INTERVAL_SEC = 2;

    public function __construct(private readonly CashPaymentService $cash) {}

    public function poll(Request $request): array
    {
        $data = $request->validate([
            'ts' => ['required', 'integer', 'min:0'],
            'fw' => ['required', 'string', 'max:32'],
            'accepting' => ['required', 'boolean'],
            'queued' => ['required', 'integer', 'min:0', 'max:1000'],
            'rssi' => ['nullable', 'integer', 'between:-127,0'],
            'uptime' => ['nullable', 'integer', 'min:0'],
            'bootReason' => ['nullable', 'string', 'max:32'],
        ]);
        $device = $this->device($request);
        $device->forceFill([
            'last_seen_at' => now(),
            'last_ip' => $request->ip(),
            'firmware_version' => $data['fw'],
            'last_state' => ['accepting' => $data['accepting'], 'queued' => $data['queued']] + array_intersect_key($data, array_flip(['rssi', 'uptime', 'bootReason'])),
        ])->save();

        return $this->state($device);
    }

    public function notes(Request $request): array
    {
        $data = $request->validate([
            'notes' => ['required', 'array', 'min:1', 'max:20'],
            'notes.*.noteUid' => ['required', 'string', 'distinct', 'regex:/^[A-Za-z0-9._:-]{4,48}$/'],
            'notes.*.nominal' => ['required', 'integer', Rule::in(CashPaymentService::NOMINALS)],
            'notes.*.sessionId' => ['nullable', 'string', 'size:26'],
            'notes.*.deviceTs' => ['nullable', 'integer', 'min:0'],
        ]);
        $device = $this->device($request);
        $results = $this->cash->receive($device, $data['notes']);
        $device->forceFill(['last_seen_at' => now()])->save();

        return ['results' => $results] + $this->state($device);
    }

    /** @return array{serverTime: int, pollIntervalSec: int, accept: bool, sessionId: ?string, required: int, paid: int, acceptUntil: ?int} */
    private function state(Device $device): array
    {
        /** @var GameSession|null $active */
        $active = $this->cash->activeFor($device);

        return [
            'serverTime' => CarbonImmutable::now()->getTimestamp(),
            'pollIntervalSec' => self::POLL_INTERVAL_SEC,
            'accept' => $active !== null && $active->cash_paid < $active->amount,
            'sessionId' => $active?->public_id,
            'required' => $active?->amount ?? 0,
            'paid' => $active?->cash_paid ?? 0,
            'acceptUntil' => $active?->paying_until?->getTimestamp(),
        ];
    }

    private function device(Request $request): Device
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');
        if (! $device->isCash()) {
            throw ApiException::of(ErrorCode::DEVICE_KIND_MISMATCH);
        }

        return $device;
    }
}
