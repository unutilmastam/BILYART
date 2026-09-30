<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchClosedDay;
use App\Domain\Branches\Models\WorkingHour;
use App\Domain\Branches\Services\BranchAccess;
use App\Domain\Branches\Services\BranchService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BranchRequest;
use App\Http\Requests\Admin\WorkingHoursRequest;
use App\Http\Resources\BranchResource;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/** Tenant scoping: global scope + route binding (foreign ids → 404). Branch restrictions: BranchAccess. */
final class BranchController extends Controller
{
    public function __construct(
        private readonly BranchService $branches,
        private readonly BranchAccess $access,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return BranchResource::collection($this->access->scope(Branch::query(), $request->user(), 'id')->orderBy('name')->get());
    }

    public function show(Request $request, Branch $branch): BranchResource
    {
        $this->authorizeBranch($request, $branch);

        return new BranchResource($branch);
    }

    public function store(BranchRequest $request): JsonResponse
    {
        return (new BranchResource($this->branches->create($request->columns())))->response()->setStatusCode(201);
    }

    public function update(BranchRequest $request, Branch $branch): BranchResource
    {
        return new BranchResource($this->branches->update($branch, $request->columns()));
    }

    /** DELETE = disable (data is never deleted). */
    public function destroy(Branch $branch): BranchResource
    {
        return new BranchResource($this->branches->update($branch, ['is_active' => false]));
    }

    public function workingHours(Request $request, Branch $branch): array
    {
        $this->authorizeBranch($request, $branch);

        return ['data' => WorkingHour::query()->where('branch_id', $branch->id)->orderBy('weekday')->get()->map(fn (WorkingHour $h) => [
            'weekday' => $h->weekday,
            'isClosed' => $h->is_closed,
            'opensAt' => $h->opens_at ? substr((string) $h->opens_at, 0, 5) : null,
            'closesAt' => $h->closes_at ? substr((string) $h->closes_at, 0, 5) : null,
        ])];
    }

    public function setWorkingHours(WorkingHoursRequest $request, Branch $branch): array
    {
        $this->branches->setWorkingHours($branch, $request->input('days'));

        return $this->workingHours($request, $branch);
    }

    public function closedDays(Request $request, Branch $branch): array
    {
        $this->authorizeBranch($request, $branch);

        return ['data' => BranchClosedDay::query()->where('branch_id', $branch->id)->where('date', '>=', now()->subDays(30)->toDateString())
            ->orderBy('date')->get()->map(fn (BranchClosedDay $d) => ['id' => $d->public_id, 'date' => $d->date->toDateString(), 'reason' => $d->reason])];
    }

    public function addClosedDay(Request $request, Branch $branch): JsonResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'reason' => ['nullable', 'string', 'max:255']]);
        $day = $this->branches->addClosedDay($branch, $data['date'], $data['reason'] ?? null);

        return response()->json(['id' => $day->public_id, 'date' => $day->date->toDateString(), 'reason' => $day->reason], 201);
    }

    public function removeClosedDay(Branch $branch, BranchClosedDay $day): Response
    {
        abort_unless($day->branch_id === $branch->id, 404);
        $this->branches->removeClosedDay($branch, $day);

        return response()->noContent();
    }

    private function authorizeBranch(Request $request, Branch $branch): void
    {
        if (! $this->access->canAccess($request->user(), $branch->id)) {
            throw ApiException::of(ErrorCode::NOT_FOUND);
        }
    }
}
