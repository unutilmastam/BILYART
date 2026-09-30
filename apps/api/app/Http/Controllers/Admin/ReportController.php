<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Services\BranchAccess;
use App\Domain\Reports\Services\ReportService;
use App\Http\Controllers\Controller;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

final class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly BranchAccess $access,
    ) {}

    public function daily(Request $request): array
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'branchId' => ['nullable', 'string', 'size:26']]);

        return $this->reports->daily(CarbonImmutable::parse($data['date']), $this->branch($request));
    }

    public function monthly(Request $request): array
    {
        $data = $request->validate(['month' => ['required', 'date_format:Y-m'], 'branchId' => ['nullable', 'string', 'size:26']]);

        return $this->reports->monthly(CarbonImmutable::parse($data['month'].'-01'), $this->branch($request));
    }

    private function branch(Request $request): ?Branch
    {
        $id = $request->query('branchId');
        $allowed = $this->access->allowedBranchIds($request->user());
        if ($id === null) {
            if ($allowed !== null) {
                throw ApiException::of(ErrorCode::FORBIDDEN); // restricted staff must pick a branch
            }

            return null;
        }
        $branch = Branch::query()->where('public_id', $id)->first();
        if ($branch === null || ! $this->access->canAccess($request->user(), $branch->id)) {
            throw ApiException::of(ErrorCode::NOT_FOUND);
        }

        return $branch;
    }
}
