<?php

namespace Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PruneIdempotencyKeysTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function expired_and_stuck_keys_are_pruned_but_recent_ones_kept(): void
    {
        $row = fn (string $key, $createdAt, ?int $code) => [
            'principal_type' => 'USER', 'principal_id' => 1, 'key' => $key, 'route' => 'POST x',
            'request_hash' => str_repeat('a', 64), 'response_code' => $code, 'response_body' => '{}', 'created_at' => $createdAt,
        ];
        DB::table('idempotency_keys')->insert([
            $row('old-000000000000000', now()->subHours(49), 201),
            $row('stuck-0000000000000', now()->subMinutes(11), null),
            $row('fresh-0000000000000', now()->subHour(), 201),
            $row('inflight-0000000000', now()->subMinute(), null),
        ]);

        $this->artisan('idempotency:prune')->assertSuccessful();

        $this->assertEqualsCanonicalizing(['fresh-0000000000000', 'inflight-0000000000'], DB::table('idempotency_keys')->pluck('key')->all());
    }
}
