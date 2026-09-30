<?php

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsTenantData;
use Tests\TestCase;

/** Spec §32: the database itself allows at most one occupying session per table. */
class DoubleBookingConstraintTest extends TestCase
{
    use BuildsTenantData, RefreshDatabase;

    public static function occupyingPairs(): array
    {
        $occupying = ['RESERVED', 'STARTING', 'ACTIVE', 'COMPLETING'];
        $pairs = [];
        foreach ($occupying as $first) {
            foreach ($occupying as $second) {
                $pairs["$first then $second"] = [$first, $second];
            }
        }

        return $pairs;
    }

    #[Test]
    #[DataProvider('occupyingPairs')]
    public function a_second_occupying_session_on_the_same_table_is_rejected(string $first, string $second): void
    {
        $t = $this->tenantWithTable();
        $this->insertSession($t['tenant']->id, $t['branch']->id, $t['table']->id, $first);

        $this->expectException(QueryException::class);
        $this->insertSession($t['tenant']->id, $t['branch']->id, $t['table']->id, $second);
    }

    #[Test]
    public function finished_sessions_do_not_block_the_table(): void
    {
        $t = $this->tenantWithTable();
        foreach (['COMPLETED', 'COMPLETED', 'CANCELLED', 'FAILED'] as $status) {
            $this->insertSession($t['tenant']->id, $t['branch']->id, $t['table']->id, $status);
        }
        $this->insertSession($t['tenant']->id, $t['branch']->id, $t['table']->id, 'ACTIVE');

        $this->assertSame(5, DB::table('game_sessions')->count());
    }

    #[Test]
    public function reviving_a_finished_session_while_another_is_active_is_rejected(): void
    {
        $t = $this->tenantWithTable();
        $old = $this->insertSession($t['tenant']->id, $t['branch']->id, $t['table']->id, 'COMPLETED');
        $this->insertSession($t['tenant']->id, $t['branch']->id, $t['table']->id, 'ACTIVE');

        $this->expectException(QueryException::class);
        DB::table('game_sessions')->where('id', $old)->update(['status' => 'ACTIVE']);
    }

    #[Test]
    public function different_tables_can_be_busy_at_the_same_time(): void
    {
        $a = $this->tenantWithTable();
        $b = $this->tenantWithTable();
        $this->insertSession($a['tenant']->id, $a['branch']->id, $a['table']->id, 'ACTIVE');
        $this->insertSession($b['tenant']->id, $b['branch']->id, $b['table']->id, 'ACTIVE');

        $this->assertSame(2, DB::table('game_sessions')->whereIn('status', ['ACTIVE'])->count());
    }

    #[Test]
    public function session_integrity_checks_reject_invalid_rows(): void
    {
        $t = $this->tenantWithTable();
        $cases = [
            'unknown status' => ['status' => 'PLAYING'],
            'unknown payment status' => ['payment_status' => 'VERIFIED_BY_CCTV'],
            'end before start' => ['start_at' => now(), 'end_at' => now()->subMinute()],
            'active without start' => ['start_at' => null, 'end_at' => null],
            'zero duration' => ['duration_minutes' => 0],
        ];
        foreach ($cases as $name => $override) {
            try {
                DB::transaction(fn () => $this->insertSession($t['tenant']->id, $t['branch']->id, $t['table']->id, 'ACTIVE', $override));
                $this->fail("Accepted invalid session: $name");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
