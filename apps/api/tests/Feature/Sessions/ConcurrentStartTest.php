<?php

namespace Tests\Feature\Sessions;

use App\Domain\Sessions\Services\SessionService;
use App\Domain\Tables\Models\BilliardTable;
use App\Domain\Tablets\Models\Tablet;
use App\Domain\Tenancy\TenantContext;
use App\Support\Http\ApiException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsSessionFixtures;
use Tests\TestCase;

/**
 * Spec §32 with real parallelism: N forked PHP processes, each with its own DB
 * connection, try to reserve the same table at the same instant. Exactly one
 * must win; everyone else gets TABLE_UNAVAILABLE. Uses committed data
 * (DatabaseTruncation), because RefreshDatabase's transaction is invisible to
 * other connections.
 */
class ConcurrentStartTest extends TestCase
{
    use BuildsSessionFixtures, DatabaseTruncation;

    private const WORKERS = 8;

    protected function tearDown(): void
    {
        // Leave no committed rows behind for the RefreshDatabase tests that follow.
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    #[Test]
    public function parallel_prepare_on_one_table_has_exactly_one_winner(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the real concurrency test.');
        }

        $h = $this->hall();
        $tabletId = $h['tablet']->id;
        $tableId = $h['table']->id;
        $tenantId = $h['tenant']->id;
        $dir = sys_get_temp_dir().'/bilyart-race-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $startAt = microtime(true) + 1.0; // common start instant for all workers

        DB::disconnect(); // children must not share the parent's socket
        $pids = [];
        for ($i = 0; $i < self::WORKERS; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $this->runWorker($i, $dir, $startAt, $tenantId, $tabletId, $tableId);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
        DB::reconnect();

        $results = array_map(fn ($f) => trim((string) file_get_contents($f)), glob("$dir/*.txt"));
        array_map('unlink', glob("$dir/*.txt"));
        rmdir($dir);

        $this->assertCount(self::WORKERS, $results, 'every worker reported');
        $counts = array_count_values($results);
        $this->assertSame(1, $counts['OK'] ?? 0, 'exactly one reservation wins: '.json_encode($counts));
        $this->assertSame(self::WORKERS - 1, $counts['TABLE_UNAVAILABLE'] ?? 0, json_encode($counts));
        $this->assertSame(1, DB::table('game_sessions')->where('table_id', $tableId)->count());
    }

    private function runWorker(int $i, string $dir, float $startAt, int $tenantId, int $tabletId, int $tableId): never
    {
        $result = 'ERROR';
        try {
            DB::purge();
            DB::reconnect();
            $context = app(TenantContext::class);
            $result = $context->runAsTenant($tenantId, function () use ($startAt, $tabletId, $tableId): string {
                $tablet = Tablet::query()->findOrFail($tabletId);
                $table = BilliardTable::query()->findOrFail($tableId);
                while (microtime(true) < $startAt) {
                    usleep(200);
                }
                try {
                    app(SessionService::class)->prepare($tablet, $table, 30);

                    return 'OK';
                } catch (ApiException $e) {
                    return $e->errorCode->value;
                }
            });
        } catch (\Throwable $e) {
            $result = 'ERROR '.$e::class.': '.$e->getMessage();
        } finally {
            file_put_contents("$dir/$i.txt", $result);
            // Leave without running PHPUnit/Laravel shutdown handlers in the child.
            posix_kill(getmypid(), SIGKILL);
        }
        exit(0);
    }
}
