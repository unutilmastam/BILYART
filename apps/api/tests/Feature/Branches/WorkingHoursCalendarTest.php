<?php

namespace Tests\Feature\Branches;

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchClosedDay;
use App\Domain\Branches\Models\WorkingHour;
use App\Domain\Branches\Services\WorkingHoursCalendar;
use App\Domain\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkingHoursCalendarTest extends TestCase
{
    use RefreshDatabase;

    private function branch(array $hours, array $closed = []): Branch
    {
        return $this->asSystem(function () use ($hours, $closed): Branch {
            $tenant = Tenant::factory()->create();
            $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'timezone' => 'Asia/Tashkent']);
            foreach ($hours as $weekday => [$open, $close]) {
                $h = new WorkingHour(['branch_id' => $branch->id, 'weekday' => $weekday, 'opens_at' => $open, 'closes_at' => $close, 'is_closed' => $open === null]);
                $h->tenant_id = $tenant->id;
                $h->save();
            }
            foreach ($closed as $date) {
                $d = new BranchClosedDay(['branch_id' => $branch->id, 'date' => $date]);
                $d->tenant_id = $tenant->id;
                $d->save();
            }

            return $branch;
        });
    }

    private function openAt(Branch $branch, string $localTime): bool
    {
        return $this->asSystem(fn () => app(WorkingHoursCalendar::class)->isOpenAt($branch, CarbonImmutable::parse($localTime, 'Asia/Tashkent')->utc()));
    }

    #[Test]
    public function regular_day_hours_in_branch_timezone(): void
    {
        // 2026-10-05 is a Monday.
        $branch = $this->branch([1 => ['10:00:00', '22:00:00']]);

        $this->assertFalse($this->openAt($branch, '2026-10-05 09:59'));
        $this->assertTrue($this->openAt($branch, '2026-10-05 10:00'));
        $this->assertTrue($this->openAt($branch, '2026-10-05 21:59'));
        $this->assertFalse($this->openAt($branch, '2026-10-05 22:00'));
        $this->assertFalse($this->openAt($branch, '2026-10-06 12:00')); // Tuesday has no row → closed
    }

    #[Test]
    public function shifts_crossing_midnight_belong_to_their_start_day(): void
    {
        // Friday 20:00–02:00; Saturday is a closed day.
        $branch = $this->branch([5 => ['20:00:00', '02:00:00'], 6 => ['20:00:00', '02:00:00']], ['2026-10-10']);

        $this->assertTrue($this->openAt($branch, '2026-10-09 23:30'));
        $this->assertTrue($this->openAt($branch, '2026-10-10 01:59')); // still Friday's shift
        $this->assertFalse($this->openAt($branch, '2026-10-10 02:00'));
        $this->assertFalse($this->openAt($branch, '2026-10-10 21:00')); // Saturday closed
        $this->assertFalse($this->openAt($branch, '2026-10-11 01:00')); // Saturday's shift did not happen
    }

    #[Test]
    public function midnight_to_midnight_means_open_all_day(): void
    {
        $branch = $this->branch([1 => ['00:00:00', '00:00:00'], 7 => ['00:00:00', '00:00:00']]);

        $this->assertTrue($this->openAt($branch, '2026-10-05 00:00'));
        $this->assertTrue($this->openAt($branch, '2026-10-05 13:37'));
        $this->assertTrue($this->openAt($branch, '2026-10-05 23:59'));
    }
}
