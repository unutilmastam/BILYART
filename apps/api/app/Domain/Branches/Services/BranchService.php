<?php

namespace App\Domain\Branches\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchClosedDay;
use App\Domain\Branches\Models\WorkingHour;
use App\Domain\Tenancy\Services\LimitGuard;
use Illuminate\Support\Facades\DB;

final class BranchService
{
    public function __construct(
        private readonly LimitGuard $limits,
        private readonly AuditLogger $audit,
    ) {}

    /** Creates a branch within the license limit; a new branch is open 24/7 until hours are configured. */
    public function create(array $attributes): Branch
    {
        return $this->limits->within(LimitGuard::BRANCHES, function () use ($attributes): Branch {
            $branch = Branch::query()->create($attributes + ['is_active' => true]);
            foreach (range(1, 7) as $weekday) {
                WorkingHour::query()->create(['branch_id' => $branch->id, 'weekday' => $weekday, 'opens_at' => '00:00:00', 'closes_at' => '00:00:00', 'is_closed' => false]);
            }
            $this->audit->log('branch.created', $branch, ['name' => $branch->name]);

            return $branch;
        });
    }

    public function update(Branch $branch, array $attributes): Branch
    {
        $reactivate = ($attributes['is_active'] ?? null) === true && ! $branch->is_active;
        $apply = function () use ($branch, $attributes): Branch {
            $branch->fill($attributes)->save();
            $this->audit->log('branch.updated', $branch, ['fields' => array_keys($attributes)]);

            return $branch;
        };

        return $reactivate ? $this->limits->within(LimitGuard::BRANCHES, $apply) : DB::transaction($apply);
    }

    /**
     * Replaces the 7 weekday rows.
     *
     * @param  list<array{weekday: int, opensAt: ?string, closesAt: ?string, isClosed: bool}>  $days
     */
    public function setWorkingHours(Branch $branch, array $days): void
    {
        DB::transaction(function () use ($branch, $days): void {
            foreach ($days as $day) {
                WorkingHour::query()->updateOrCreate(
                    ['branch_id' => $branch->id, 'weekday' => $day['weekday']],
                    [
                        'opens_at' => $day['isClosed'] ? null : $day['opensAt'].':00',
                        'closes_at' => $day['isClosed'] ? null : $day['closesAt'].':00',
                        'is_closed' => $day['isClosed'],
                    ],
                );
            }
            $this->audit->log('branch.working_hours_changed', $branch, ['days' => $days]);
        });
    }

    public function addClosedDay(Branch $branch, string $date, ?string $reason): BranchClosedDay
    {
        $day = BranchClosedDay::query()->firstOrCreate(['branch_id' => $branch->id, 'date' => $date], ['reason' => $reason]);
        $this->audit->log('branch.closed_day_added', $branch, ['date' => $date]);

        return $day;
    }

    public function removeClosedDay(Branch $branch, BranchClosedDay $day): void
    {
        $day->delete();
        $this->audit->log('branch.closed_day_removed', $branch, ['date' => $day->date->toDateString()]);
    }
}
