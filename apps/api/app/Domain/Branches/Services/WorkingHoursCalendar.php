<?php

namespace App\Domain\Branches\Services;

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchClosedDay;
use App\Domain\Branches\Models\WorkingHour;
use Carbon\CarbonImmutable;

/**
 * Answers "is the branch open at instant X?" in the branch timezone (spec §39).
 * A shift belongs to the day it starts: Friday 20:00–02:00 covers Saturday
 * 00:00–02:00 even if Saturday is a closed day. 00:00–00:00 = open all day.
 */
final class WorkingHoursCalendar
{
    public function isOpenAt(Branch $branch, CarbonImmutable $instant): bool
    {
        $local = $instant->setTimezone($branch->timezone);
        $hours = WorkingHour::query()->where('branch_id', $branch->id)->get()->keyBy('weekday');
        $closed = BranchClosedDay::query()->where('branch_id', $branch->id)
            ->whereIn('date', [$local->toDateString(), $local->subDay()->toDateString()])
            ->pluck('date')->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())->all();

        // Nothing configured yet → open (a new branch is 24/7 until the owner sets hours).
        if ($hours->isEmpty()) {
            return ! in_array($local->toDateString(), $closed, true);
        }

        $time = $local->format('H:i:s');

        // Today's shift.
        $today = $hours->get($local->dayOfWeekIso);
        if ($today && ! $today->is_closed && ! in_array($local->toDateString(), $closed, true)) {
            $opens = (string) $today->opens_at;
            $closes = (string) $today->closes_at;
            $open = $closes > $opens ? ($time >= $opens && $time < $closes) : ($time >= $opens);
            if ($open) {
                return true;
            }
        }

        // Yesterday's shift running past midnight.
        $yesterday = $local->subDay();
        $prev = $hours->get($yesterday->dayOfWeekIso);
        if ($prev && ! $prev->is_closed && ! in_array($yesterday->toDateString(), $closed, true)) {
            $opens = (string) $prev->opens_at;
            $closes = (string) $prev->closes_at;
            if ($closes <= $opens && $time < $closes) {
                return true;
            }
        }

        return false;
    }
}
