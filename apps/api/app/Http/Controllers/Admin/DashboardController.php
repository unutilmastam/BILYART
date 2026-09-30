<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Services\BranchAccess;
use App\Domain\Devices\Models\Device;
use App\Domain\Reports\Services\ReportService;
use App\Domain\Sessions\Services\TableStatusResolver;
use App\Domain\Tables\Models\BilliardTable;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantSettings;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** spec §6 / §42: today's figures per branch, live table states, device status — own tenant only. */
final class DashboardController extends Controller
{
    public function __invoke(Request $request, ReportService $reports, TableStatusResolver $statuses, TenantSettings $settings, TenantContext $context, BranchAccess $access): array
    {
        $now = CarbonImmutable::now();
        $warn = (int) $settings->for(Tenant::query()->findOrFail($context->requireTenantId()))['warn_before_minutes'];
        $branches = $access->scope(Branch::query(), $request->user(), 'id')->where('is_active', true)->orderBy('name')->get();

        $rows = [];
        $totals = ['playing' => 0, 'available' => 0, 'sessionsToday' => 0, 'amountToday' => 0, 'minutesToday' => 0, 'unpaidToday' => 0, 'devicesOnline' => 0, 'devicesOffline' => 0];
        foreach ($branches as $branch) {
            $today = $reports->daily($now->setTimezone($branch->timezone), $branch, $now)['branches'][0];
            $tables = BilliardTable::query()->where('branch_id', $branch->id)->where('is_active', true)->orderBy('number')->get()->each->setRelation('branch', $branch);
            $resolved = $statuses->resolve($tables, $warn, $now);
            $counts = array_count_values(array_column($resolved, 'status'));
            $devices = Device::query()->where('branch_id', $branch->id)->where('status', 'PAIRED')->get();
            $online = $devices->filter(fn (Device $d) => $d->isOnline($now))->count();

            $row = [
                'branch' => ['id' => $branch->public_id, 'name' => $branch->name],
                'playing' => ($counts['BUSY'] ?? 0) + ($counts['WARNING'] ?? 0) + ($counts['STARTING'] ?? 0),
                'available' => $counts['AVAILABLE'] ?? 0,
                'sessionsToday' => $today['sessions'],
                'amountToday' => $today['amount'],
                'minutesToday' => $today['minutes'],
                'unpaidToday' => $today['unpaidCount'],
                'devicesOnline' => $online,
                'devicesOffline' => $devices->count() - $online,
                'tables' => $tables->map(fn ($t) => [
                    'id' => $t->public_id,
                    'number' => $t->number,
                    'name' => $t->name,
                    'status' => $resolved[$t->id]['status'],
                    'endAt' => $resolved[$t->id]['session']?->end_at?->toIso8601ZuluString(),
                ])->values()->all(),
            ];
            foreach ($totals as $k => $_) {
                $totals[$k] += $row[$k];
            }
            $rows[] = $row;
        }

        return ['serverTime' => $now->toIso8601ZuluString(), 'totals' => $totals, 'branches' => $rows];
    }
}
