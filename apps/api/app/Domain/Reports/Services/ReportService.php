<?php

namespace App\Domain\Reports\Services;

use App\Domain\Branches\Models\Branch;
use App\Domain\Sessions\Enums\PaymentStatus;
use App\Domain\Sessions\Enums\SessionStatus;
use App\Domain\Sessions\Models\GameSession;
use App\Domain\Tables\Models\BilliardTable;
use Carbon\CarbonImmutable;

/**
 * Daily/monthly figures per branch (spec §6 Reports, §24). Period boundaries
 * are computed in each branch's timezone and converted to UTC ranges in PHP,
 * so the SQL stays portable. Counted sessions = those that actually started.
 * Amounts are the fixed price chosen by the customer (integer UZS).
 */
final class ReportService
{
    private const COUNTED = [SessionStatus::STARTING, SessionStatus::ACTIVE, SessionStatus::COMPLETING, SessionStatus::COMPLETED];

    /** @return array{from: string, to: string, branches: list<array>, totals: array} */
    public function daily(CarbonImmutable $localDate, ?Branch $only = null, ?CarbonImmutable $now = null): array
    {
        return $this->report(fn (Branch $b) => [
            CarbonImmutable::parse($localDate->toDateString(), $b->timezone)->startOfDay(),
            CarbonImmutable::parse($localDate->toDateString(), $b->timezone)->startOfDay()->addDay(),
        ], $only, $now, $localDate->toDateString(), $localDate->toDateString());
    }

    public function monthly(CarbonImmutable $month, ?Branch $only = null, ?CarbonImmutable $now = null): array
    {
        $first = $month->startOfMonth();

        return $this->report(fn (Branch $b) => [
            CarbonImmutable::parse($first->toDateString(), $b->timezone)->startOfDay(),
            CarbonImmutable::parse($first->toDateString(), $b->timezone)->startOfDay()->addMonth(),
        ], $only, $now, $first->toDateString(), $first->endOfMonth()->toDateString());
    }

    /** @param \Closure(Branch): array{0: CarbonImmutable, 1: CarbonImmutable} $period */
    private function report(\Closure $period, ?Branch $only, ?CarbonImmutable $now, string $fromLabel, string $toLabel): array
    {
        $now ??= CarbonImmutable::now();
        $branches = $only ? collect([$only]) : Branch::query()->orderBy('name')->get();
        $rows = [];
        foreach ($branches as $branch) {
            [$from, $to] = $period($branch);
            $rows[] = $this->branchFigures($branch, $from->utc(), $to->utc(), $now);
        }

        $totals = ['sessions' => 0, 'minutes' => 0, 'amount' => 0, 'unpaidCount' => 0, 'unpaidAmount' => 0];
        foreach ($rows as $r) {
            foreach ($totals as $k => $_) {
                $totals[$k] += $r[$k];
            }
        }

        return ['from' => $fromLabel, 'to' => $toLabel, 'branches' => $rows, 'totals' => $totals];
    }

    private function branchFigures(Branch $branch, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $now): array
    {
        $sessions = GameSession::query()
            ->where('branch_id', $branch->id)
            ->whereIn('status', array_map(fn ($s) => $s->value, self::COUNTED))
            ->where('start_at', '>=', $from)->where('start_at', '<', $to)
            ->get(['id', 'table_id', 'status', 'start_at', 'end_at', 'ended_at', 'amount', 'payment_status', 'duration_minutes']);
        $tables = BilliardTable::query()->where('branch_id', $branch->id)->orderBy('number')->get(['id', 'public_id', 'number', 'name', 'is_active'])->keyBy('id');

        $perTable = [];
        $figures = ['sessions' => 0, 'minutes' => 0, 'amount' => 0, 'unpaidCount' => 0, 'unpaidAmount' => 0];
        foreach ($sessions as $s) {
            $minutes = $this->playedMinutes($s, $now);
            $figures['sessions']++;
            $figures['minutes'] += $minutes;
            $figures['amount'] += $s->amount;
            if ($s->payment_status === PaymentStatus::UNPAID) {
                $figures['unpaidCount']++;
                $figures['unpaidAmount'] += $s->amount;
            }
            $perTable[$s->table_id] ??= ['sessions' => 0, 'minutes' => 0, 'amount' => 0];
            $perTable[$s->table_id]['sessions']++;
            $perTable[$s->table_id]['minutes'] += $minutes;
            $perTable[$s->table_id]['amount'] += $s->amount;
        }

        $periodMinutes = max(1, (int) round(($to->getTimestamp() - $from->getTimestamp()) / 60));
        $activeTables = max(1, $tables->where('is_active', true)->count());

        return $figures + [
            'branch' => ['id' => $branch->public_id, 'name' => $branch->name, 'timezone' => $branch->timezone],
            'utilizationPercent' => round(100 * $figures['minutes'] / ($activeTables * $periodMinutes), 1),
            'tables' => $tables->map(fn ($t) => [
                'id' => $t->public_id,
                'number' => $t->number,
                'name' => $t->name,
                'sessions' => $perTable[$t->id]['sessions'] ?? 0,
                'minutes' => $perTable[$t->id]['minutes'] ?? 0,
                'amount' => $perTable[$t->id]['amount'] ?? 0,
                'utilizationPercent' => round(100 * ($perTable[$t->id]['minutes'] ?? 0) / $periodMinutes, 1),
            ])->values()->all(),
        ];
    }

    /** Actual playing time: until the early stop, the end, or now (running sessions). */
    private function playedMinutes(GameSession $s, CarbonImmutable $now): int
    {
        if ($s->start_at === null) {
            return 0;
        }
        $end = $s->ended_at ?? $s->end_at ?? $now;
        if ($end->greaterThan($now)) {
            $end = $now;
        }

        return max(0, intdiv($end->getTimestamp() - $s->start_at->getTimestamp(), 60));
    }
}
