<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Branches\Services\BranchAccess;
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

final class TableController extends Controller
{
    public function __construct(
        private readonly TableService $tables,
        private readonly BranchAccess $access,
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
        $table = $this->tables->create($request->columns() + ['branch_id' => $branch->id]);

        return (new TableResource($table->load(['branch', 'pricingPlan', 'device'])))->response()->setStatusCode(201);
    }

    public function update(TableRequest $request, BilliardTable $table): TableResource
    {
        return new TableResource($this->tables->update($table, $request->columns())->load(['branch', 'pricingPlan', 'device']));
    }

    public function destroy(BilliardTable $table): TableResource
    {
        return new TableResource($this->tables->update($table, ['is_active' => false])->load(['branch', 'pricingPlan', 'device']));
    }
}
