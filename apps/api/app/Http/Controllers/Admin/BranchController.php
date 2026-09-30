<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Branches\Models\Branch;
use App\Http\Controllers\Controller;
use App\Http\Resources\BranchResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Read side of branches (write side: Phase 6). Tenant scoping comes from the global scope + route binding. */
final class BranchController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return BranchResource::collection(Branch::query()->orderBy('name')->get());
    }

    public function show(Branch $branch): BranchResource
    {
        return new BranchResource($branch);
    }
}
