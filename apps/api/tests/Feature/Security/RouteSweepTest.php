<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RouteDef;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsSessionFixtures;
use Tests\Concerns\BuildsTenantData;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Sweeps the real route table instead of hand-picked endpoints (spec §43
 * items 1–4, SECURITY.md §2). A new route with a model parameter is covered
 * automatically; a new parameter name without a fixture fails the test.
 */
class RouteSweepTest extends TestCase
{
    use BuildsSessionFixtures, BuildsTenantData, CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** @return list<array{method: string, uri: string, route: RouteDef}> */
    private function routes(string $prefix): array
    {
        $out = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), $prefix)) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                $out[] = ['method' => $method, 'uri' => $route->uri(), 'route' => $route];
            }
        }
        $this->assertNotEmpty($out, "no routes under $prefix");

        return $out;
    }

    /** @param array<string, string> $keys */
    private function fill(string $uri, array $keys): string
    {
        return preg_replace_callback('/\{(\w+)\??\}/', function (array $m) use ($keys, $uri): string {
            $this->assertArrayHasKey($m[1], $keys, "RouteSweepTest has no fixture for {{$m[1]}} in $uri — add one.");

            return $keys[$m[1]];
        }, $uri);
    }

    /** Every model a client admin route can bind, owned by the given hall's tenant. */
    private function ownedRecords(array $h): array
    {
        return $this->asSystem(function () use ($h): array {
            $t = $h['tenant']->id;
            $sessionId = $this->insertSession($t, $h['branch']->id, $h['table']->id, 'COMPLETED');
            $sessionPublicId = DB::table('game_sessions')->where('id', $sessionId)->value('public_id');
            $ids = [
                'photo' => (string) Str::ulid(), 'day' => (string) Str::ulid(), 'chat' => (string) Str::ulid(),
                'notification' => (string) Str::ulid(), 'integration' => (string) Str::ulid(),
            ];
            $photoPath = "tenants/$t/sessions/$sessionPublicId/".strtoupper((string) Str::ulid()).'.jpg';
            Storage::disk('local')->put($photoPath, 'jpeg-bytes');
            DB::table('session_photos')->insert([
                'public_id' => $ids['photo'], 'tenant_id' => $t, 'session_id' => $sessionId, 'storage_path' => $photoPath,
                'mime_type' => 'image/jpeg', 'size' => 1, 'width' => 640, 'height' => 480, 'sha256' => str_repeat('0', 64), 'created_at' => now(),
            ]);
            DB::table('branch_closed_days')->insert([
                'public_id' => $ids['day'], 'tenant_id' => $t, 'branch_id' => $h['branch']->id, 'date' => '2030-01-01', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $integrationId = DB::table('telegram_integrations')->insertGetId([
                'public_id' => $ids['integration'], 'tenant_id' => $t, 'bot_token_encrypted' => encrypt('1:x'), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('telegram_chats')->insert([
                'public_id' => $ids['chat'], 'tenant_id' => $t, 'integration_id' => $integrationId, 'chat_id' => random_int(1, PHP_INT_MAX), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('notifications')->insert([
                'public_id' => $ids['notification'], 'tenant_id' => $t, 'type' => 'device_offline', 'severity' => 'WARNING',
                'dedupe_key' => 'sweep:'.Str::uuid(), 'payload' => json_encode(['text' => 'x']), 'created_at' => now(),
            ]);
            $user = $this->tenantUser('CLIENT_MANAGER', $h['tenant']);
            $ids['paymentRequest'] = (string) Str::ulid();
            $receiptPath = "tenants/$t/receipts/".strtoupper((string) Str::ulid()).'.jpg';
            Storage::disk('local')->put($receiptPath, 'jpeg-bytes');
            DB::table('subscription_payment_requests')->insert([
                'public_id' => $ids['paymentRequest'], 'tenant_id' => $t, 'months' => 1, 'branch_count' => 1, 'price_per_branch' => 100000,
                'amount' => 100000, 'status' => 'PENDING', 'receipt_path' => $receiptPath, 'receipt_sha256' => str_repeat('0', 64),
                'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return [
                'branch' => $h['branch']->public_id, 'table' => $h['table']->public_id, 'plan' => $h['plan']->public_id,
                'device' => $h['device']->public_id, 'tablet' => $h['tablet']->public_id, 'user' => $user->public_id,
                'session' => $sessionPublicId,
                'photo' => $ids['photo'], 'day' => $ids['day'], 'chat' => $ids['chat'], 'notification' => $ids['notification'],
                'paymentRequest' => $ids['paymentRequest'],
            ];
        });
    }

    /** Row counts + update stamps of tenant B, to prove nothing was changed by A's requests. */
    private function fingerprint(int $tenantId): array
    {
        $tables = ['branches', 'branch_closed_days', 'pricing_plans', 'billiard_tables', 'devices', 'tablets', 'users', 'game_sessions', 'session_photos', 'telegram_chats', 'notifications', 'subscription_payment_requests'];

        return $this->asSystem(fn () => collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->where('tenant_id', $tenantId)->get()->map(fn ($r) => json_encode($r))->sort()->values()->all()])->all());
    }

    #[Test]
    public function every_client_admin_route_with_a_model_parameter_returns_404_for_another_tenants_record(): void
    {
        $a = $this->hall();
        $b = $this->hall();
        $ownerA = $this->tenantUser('CLIENT_OWNER', $a['tenant']);
        $keysA = $this->ownedRecords($a);
        $keysB = $this->ownedRecords($b);
        $before = $this->fingerprint($b['tenant']->id);

        $checked = 0;
        foreach ($this->routes('api/admin/') as $r) {
            $params = $r['route']->parameterNames();
            if ($params === []) {
                continue;
            }
            // All parameters from tenant B, then (for nested routes) own parent + foreign child.
            $variants = [$keysB];
            if (count($params) > 1) {
                $mixed = $keysB;
                $mixed[$params[0]] = $keysA[$params[0]];
                $variants[] = $mixed;
            }
            foreach ($variants as $keys) {
                $uri = '/'.$this->fill($r['uri'], $keys);
                $res = $this->actingAs($ownerA)->json($r['method'], $uri, [], ['Idempotency-Key' => (string) Str::uuid()]);
                $this->assertSame(404, $res->status(), "{$r['method']} $uri answered {$res->status()} for a foreign record: ".$res->getContent());
                $this->assertSame('NOT_FOUND', $res->json('error.code'), "{$r['method']} $uri");
                $checked++;
            }
        }

        $this->assertGreaterThanOrEqual(30, $checked);
        $this->assertSame($before, $this->fingerprint($b['tenant']->id), 'tenant B data changed');
    }

    #[Test]
    public function the_same_routes_work_for_the_owning_tenant_so_the_404s_are_meaningful(): void
    {
        $a = $this->hall();
        $ownerA = $this->tenantUser('CLIENT_OWNER', $a['tenant']);
        $keysA = $this->ownedRecords($a);

        foreach ($this->routes('api/admin/') as $r) {
            if ($r['method'] !== 'GET' || $r['route']->parameterNames() === []) {
                continue;
            }
            $uri = '/'.$this->fill($r['uri'], $keysA);
            $this->assertSame(200, $this->actingAs($ownerA)->get($uri)->status(), "GET $uri");
        }
    }

    #[Test]
    public function guests_get_401_on_every_admin_super_and_account_route(): void
    {
        $keys = array_fill_keys(['branch', 'table', 'plan', 'user', 'session', 'photo', 'device', 'tablet', 'day', 'chat', 'notification', 'tenant', 'release', 'paymentRequest'], (string) Str::ulid());
        $public = ['api/auth/csrf', 'api/auth/login'];

        $checked = 0;
        foreach ([...$this->routes('api/admin/'), ...$this->routes('api/super/'), ...$this->routes('api/me'), ...$this->routes('api/auth/')] as $r) {
            if (in_array($r['uri'], $public, true)) {
                continue;
            }
            $uri = '/'.$this->fill($r['uri'], $keys);
            $res = $this->json($r['method'], $uri);
            $this->assertSame(401, $res->status(), "{$r['method']} $uri answered {$res->status()} to a guest");
            $this->assertSame('UNAUTHENTICATED', $res->json('error.code'));
            $checked++;
        }
        $this->assertGreaterThanOrEqual(80, $checked);
    }

    #[Test]
    public function client_users_get_403_on_every_super_admin_route(): void
    {
        $h = $this->hall();
        $owner = $this->tenantUser('CLIENT_OWNER', $h['tenant']);
        $keys = ['tenant' => $h['tenant']->public_id, 'release' => (string) Str::ulid(), 'notification' => (string) Str::ulid(), 'paymentRequest' => (string) Str::ulid()];

        foreach ($this->routes('api/super/') as $r) {
            $uri = '/'.$this->fill($r['uri'], $keys);
            $res = $this->actingAs($owner)->json($r['method'], $uri, [], ['Idempotency-Key' => (string) Str::uuid()]);
            $this->assertSame(403, $res->status(), "{$r['method']} $uri answered {$res->status()} to a client owner");
        }
        $this->assertSame('ACTIVE', $this->asSystem(fn () => $h['tenant']->fresh()->status_flag->value ?? $h['tenant']->fresh()->status_flag));
    }

    #[Test]
    public function tablet_and_device_routes_require_their_own_credentials(): void
    {
        $h = $this->hall();
        $owner = $this->tenantUser('CLIENT_OWNER', $h['tenant']);
        $keys = ['session' => (string) Str::ulid(), 'version' => '1.0.0'];
        $open = ['api/tablet/register', 'api/tablet/pairing-status', 'device/v1/register', 'device/v1/pairing-status'];

        foreach ([...$this->routes('api/tablet/'), ...$this->routes('device/v1/')] as $r) {
            if (in_array($r['uri'], $open, true)) {
                continue;
            }
            $uri = '/'.$this->fill($r['uri'], $keys);
            $headers = ['Idempotency-Key' => (string) Str::uuid()];

            $this->json($r['method'], $uri, [], $headers)->assertStatus(401);
            // A logged-in admin session is not a tablet/device credential.
            $this->actingAs($owner)->json($r['method'], $uri, [], $headers)->assertStatus(401);
            $this->app['auth']->forgetGuards();
            // Tokens are not interchangeable: the tablet token is not a device token and vice versa.
            $wrong = str_starts_with($r['uri'], 'device/')
                ? ['Authorization' => 'Bearer '.$h['token']]
                : ['Authorization' => 'Device '.$h['device']->device_code.'.'.$h['token']];
            $this->json($r['method'], $uri, [], $headers + $wrong)->assertStatus(401);
        }
    }

    #[Test]
    public function a_tablet_cannot_read_or_act_on_another_tenants_session(): void
    {
        $a = $this->hall();
        $b = $this->hall();
        $sessionB = $this->asSystem(fn () => DB::table('game_sessions')->where('id', $this->insertSession($b['tenant']->id, $b['branch']->id, $b['table']->id, 'RESERVED'))->value('public_id'));

        foreach ($this->routes('api/tablet/sessions/{session}') as $r) {
            $uri = '/'.$this->fill($r['uri'], ['session' => $sessionB]);
            $res = $r['method'] === 'GET' ? $this->withToken($a['token'])->getJson($uri) : $this->tabletPost($a['token'], $uri);
            $this->assertSame(404, $res->status(), "{$r['method']} $uri answered {$res->status()}");
        }
        $this->assertSame('RESERVED', $this->asSystem(fn () => DB::table('game_sessions')->where('public_id', $sessionB)->value('status')));
    }
}
