<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Services\BranchAccess;
use App\Domain\Devices\Enums\CommandType;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Services\DeviceCommandBus;
use App\Domain\Devices\Services\DeviceRegistry;
use App\Domain\Tablets\Enums\TabletStatus;
use App\Domain\Tablets\Models\Tablet;
use App\Domain\Tablets\Services\TabletRegistry;
use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceResource;
use App\Http\Resources\TabletResource;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Client Admin → Devices (ESP32) and Tablets (spec §6 Devices, §17, §37). */
final class DeviceController extends Controller
{
    public function __construct(
        private readonly DeviceRegistry $devices,
        private readonly TabletRegistry $tablets,
        private readonly BranchAccess $access,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return DeviceResource::collection(
            $this->access->scope(Device::query(), $request->user())->where('status', 'PAIRED')->with(['branch', 'tables'])->orderBy('branch_id')->orderBy('id')->get()
        );
    }

    public function pair(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6'], 'branchId' => ['required', 'string', 'size:26']]);
        $branch = $this->branch($request, $data['branchId']);

        return (new DeviceResource($this->devices->pair($request->user(), $data['code'], $branch)->load(['branch', 'tables'])))->response()->setStatusCode(201);
    }

    public function move(Request $request, Device $device): DeviceResource
    {
        $data = $request->validate(['branchId' => ['required', 'string', 'size:26']]);
        $this->assertPaired($request, $device);

        return new DeviceResource($this->devices->moveToBranch($device, $this->branch($request, $data['branchId']))->load(['branch', 'tables']));
    }

    public function unpair(Request $request, Device $device): DeviceResource
    {
        $this->assertPaired($request, $device);

        return new DeviceResource($this->devices->unpair($request->user(), $device)->load(['branch', 'tables']));
    }

    public function ping(Request $request, Device $device, DeviceCommandBus $bus): JsonResponse
    {
        $this->assertPaired($request, $device);
        if ($device->isCash()) {
            throw ApiException::of(ErrorCode::DEVICE_KIND_MISMATCH); // the bill acceptor takes no lamp commands
        }
        $command = $bus->queue($device, CommandType::PING, [], null, 60);

        return response()->json(['commandId' => $command->public_id], 202);
    }

    public function tablets(Request $request): AnonymousResourceCollection
    {
        return TabletResource::collection(
            $this->access->scope(Tablet::query(), $request->user())->where('status', TabletStatus::PAIRED->value)->with('branch')->orderBy('branch_id')->get()
        );
    }

    public function pairTablet(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'digits:6'],
            'branchId' => ['required', 'string', 'size:26'],
            'name' => ['nullable', 'string', 'max:100'],
        ]);
        $branch = Branch::query()->where('public_id', $data['branchId'])->where('is_active', true)->first();
        if ($branch === null || ! $this->access->canAccess($request->user(), $branch->id)) {
            throw new ApiException(ErrorCode::VALIDATION_FAILED, [], ['fields' => ['branchId' => [__('validation.exists', ['attribute' => 'branch'])]]]);
        }

        return (new TabletResource($this->tablets->pair($request->user(), $data['code'], $branch, $data['name'] ?? null)->load('branch')))->response()->setStatusCode(201);
    }

    public function revokeTablet(Request $request, Tablet $tablet): TabletResource
    {
        if ($tablet->status !== TabletStatus::PAIRED || ! $this->access->canAccess($request->user(), (int) $tablet->branch_id)) {
            throw ApiException::of(ErrorCode::NOT_FOUND);
        }

        return new TabletResource($this->tablets->revoke($request->user(), $tablet)->load('branch'));
    }

    private function branch(Request $request, string $publicId): Branch
    {
        $branch = Branch::query()->where('public_id', $publicId)->where('is_active', true)->first();
        if ($branch === null || ! $this->access->canAccess($request->user(), $branch->id)) {
            throw new ApiException(ErrorCode::VALIDATION_FAILED, [], ['fields' => ['branchId' => [__('validation.exists', ['attribute' => 'branch'])]]]);
        }

        return $branch;
    }

    private function assertPaired(Request $request, Device $device): void
    {
        if ($device->status->value !== 'PAIRED' || ! $this->access->canAccess($request->user(), (int) $device->branch_id)) {
            throw ApiException::of(ErrorCode::NOT_FOUND);
        }
    }
}
