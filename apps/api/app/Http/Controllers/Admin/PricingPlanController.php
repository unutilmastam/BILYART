<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Pricing\Models\PricingPlan;
use App\Domain\Pricing\Services\PricingPlanService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PricingPlanRequest;
use App\Http\Resources\PricingPlanResource;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class PricingPlanController extends Controller
{
    public function __construct(private readonly PricingPlanService $plans) {}

    public function index(): AnonymousResourceCollection
    {
        return PricingPlanResource::collection(PricingPlan::query()->with('branch')->orderBy('name')->get());
    }

    public function show(PricingPlan $plan): PricingPlanResource
    {
        return new PricingPlanResource($plan->load('branch'));
    }

    public function store(PricingPlanRequest $request): JsonResponse
    {
        $plan = $this->plans->create($this->columns($request) + ['type' => 'HOURLY', 'is_active' => true]);

        return (new PricingPlanResource($plan->load('branch')))->response()->setStatusCode(201);
    }

    public function update(PricingPlanRequest $request, PricingPlan $plan): PricingPlanResource
    {
        return new PricingPlanResource($this->plans->update($plan, $this->columns($request))->load('branch'));
    }

    public function destroy(PricingPlan $plan): PricingPlanResource
    {
        return new PricingPlanResource($this->plans->update($plan, ['is_active' => false])->load('branch'));
    }

    private function columns(PricingPlanRequest $request): array
    {
        $columns = $request->columns();
        if ($columns === null) {
            throw new ApiException(ErrorCode::VALIDATION_FAILED, [], ['fields' => ['branchId' => [__('validation.exists', ['attribute' => 'branch'])]]]);
        }

        return $columns;
    }
}
