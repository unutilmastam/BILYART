<?php

namespace Tests\Feature\Http;

use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/** Spec §31: retries/double clicks never execute a state change twice. */
class IdempotencyTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private int $executions = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $counter = &$this->executions;
        Route::middleware(['web', 'auth:web', 'tenant.user', 'idempotency:required'])->group(function () use (&$counter): void {
            Route::post('/_test/idem', function (Request $request) use (&$counter) {
                $counter++;

                return response()->json(['n' => $counter, 'echo' => $request->input('v')], 201);
            });
            Route::post('/_test/idem-fail', function () use (&$counter) {
                $counter++;
                throw ApiException::of(ErrorCode::TABLE_UNAVAILABLE);
            });
            Route::post('/_test/idem-crash', function () use (&$counter) {
                $counter++;
                throw new \RuntimeException('boom');
            });
        });
    }

    #[Test]
    public function the_same_key_and_body_replays_the_first_response_without_re_executing(): void
    {
        $user = $this->tenantUser();
        $headers = ['Idempotency-Key' => 'key-0000000000000001'];

        $first = $this->actingAs($user)->withHeaders($headers)->postJson('/_test/idem', ['v' => 'a'])->assertStatus(201);
        $second = $this->actingAs($user)->withHeaders($headers)->postJson('/_test/idem', ['v' => 'a'])->assertStatus(201);

        $this->assertSame(1, $this->executions);
        $this->assertSame($first->json(), $second->json());
        $second->assertHeader('Idempotent-Replayed', 'true');
    }

    #[Test]
    public function the_same_key_with_a_different_body_is_rejected(): void
    {
        $user = $this->tenantUser();
        $headers = ['Idempotency-Key' => 'key-0000000000000002'];

        $this->actingAs($user)->withHeaders($headers)->postJson('/_test/idem', ['v' => 'a'])->assertStatus(201);
        $this->actingAs($user)->withHeaders($headers)->postJson('/_test/idem', ['v' => 'b'])
            ->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
        $this->assertSame(1, $this->executions);
    }

    #[Test]
    public function keys_are_scoped_per_principal(): void
    {
        $headers = ['Idempotency-Key' => 'key-0000000000000003'];

        $this->actingAs($this->tenantUser())->withHeaders($headers)->postJson('/_test/idem', ['v' => 'a'])->assertStatus(201);
        $this->actingAs($this->tenantUser())->withHeaders($headers)->postJson('/_test/idem', ['v' => 'a'])->assertStatus(201);
        $this->assertSame(2, $this->executions);
    }

    #[Test]
    public function missing_or_malformed_keys_are_rejected_when_required(): void
    {
        $user = $this->tenantUser();

        $this->actingAs($user)->postJson('/_test/idem', ['v' => 'a'])->assertStatus(400)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');
        $this->actingAs($user)->withHeaders(['Idempotency-Key' => 'short'])->postJson('/_test/idem')->assertStatus(400);
        $this->assertSame(0, $this->executions);
    }

    #[Test]
    public function business_errors_are_replayed_but_server_errors_can_be_retried(): void
    {
        $user = $this->tenantUser();

        $h = ['Idempotency-Key' => 'key-0000000000000004'];
        $this->actingAs($user)->withHeaders($h)->postJson('/_test/idem-fail')->assertStatus(409)->assertJsonPath('error.code', 'TABLE_UNAVAILABLE');
        $this->actingAs($user)->withHeaders($h)->postJson('/_test/idem-fail')->assertStatus(409)->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(1, $this->executions);

        $h = ['Idempotency-Key' => 'key-0000000000000005'];
        $this->actingAs($user)->withHeaders($h)->postJson('/_test/idem-crash')->assertStatus(500);
        $this->actingAs($user)->withHeaders($h)->postJson('/_test/idem-crash')->assertStatus(500);
        $this->assertSame(3, $this->executions);
        $this->assertSame(0, DB::table('idempotency_keys')->where('key', 'key-0000000000000005')->count());
    }
}
