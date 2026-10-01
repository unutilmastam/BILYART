<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Auth\Permissions;
use App\Domain\Branches\Services\BranchAccess;
use App\Domain\Devices\Services\DeviceRegistry;
use App\Domain\Tables\Models\BilliardTable;
use App\Domain\Tables\Services\TableService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TableRequest;
use App\Http\Resources\TableResource;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

final class TableController extends Controller
{
    public function __construct(
        private readonly TableService $tables,
        private readonly BranchAccess $access,
        private readonly DeviceRegistry $devices,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['branchId' => ['nullable', 'string', 'size:26']]);
        $query = $this->access->scope(BilliardTable::query(), $request->user())->with(['branch', 'pricingPlan', 'device'])->orderBy('branch_id')->orderBy('number');
        if ($branchId = $request->query('branchId')) {
            $query->whereHas('branch', fn ($q) => $q->where('public_id', $branchId));
        }

        return TableResource::collection($query->get());
    }

    public function show(Request $request, BilliardTable $table): TableResource
    {
        if (! $this->access->canAccess($request->user(), $table->branch_id)) {
            throw ApiException::of(ErrorCode::NOT_FOUND);
        }

        return new TableResource($table->load(['branch', 'pricingPlan', 'device']));
    }

    public function store(TableRequest $request): JsonResponse
    {
        $branch = $request->branch();
        if ($branch === null) {
            throw new ApiException(ErrorCode::VALIDATION_FAILED, [], ['fields' => ['branchId' => [__('validation.exists', ['attribute' => 'branch'])]]]);
        }
        $table = DB::transaction(function () use ($request, $branch): BilliardTable {
            $table = $this->tables->create($request->columns() + ['branch_id' => $branch->id]);

            return $this->wire($request, $table);
        });

        return (new TableResource($table->load(['branch', 'pricingPlan', 'device'])))->response()->setStatusCode(201);
    }

    public function update(TableRequest $request, BilliardTable $table): TableResource
    {
        $table = DB::transaction(fn () => $this->wire($request, $this->tables->update($table, $request->columns())));

        return new TableResource($table->load(['branch', 'pricingPlan', 'device']));
    }

    /** Wiring the lamp to a relay channel needs devices.manage as well (owner/manager). */
    private function wire(TableRequest $request, BilliardTable $table): BilliardTable
    {
        if (! $request->wantsWiring()) {
            return $table;
        }
        if (! Permissions::roleHas($request->user()->role, 'devices.manage')) {
            throw ApiException::of(ErrorCode::FORBIDDEN);
        }
        $device = $request->device();

        return $this->devices->assignChannel($table, $device, $device ? (int) $request->input('deviceChannel') : null);
    }

    public function destroy(BilliardTable $table): TableResource
    {
        return new TableResource($this->tables->update($table, ['is_active' => false])->load(['branch', 'pricingPlan', 'device']));
    }
}
