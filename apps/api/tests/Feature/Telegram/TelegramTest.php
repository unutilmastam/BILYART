<?php

namespace Tests\Feature\Telegram;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Telegram\Models\TelegramChat;
use App\Domain\Telegram\Models\TelegramIntegration;
use App\Domain\Users\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsSessionFixtures;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/** Spec §24, §43 item 15. Only the Telegram HTTP layer is faked. */
class TelegramTest extends TestCase
{
    use BuildsSessionFixtures, CreatesUsers, RefreshDatabase;

    private const TOKEN_A = '1234567890:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsawA';

    private const TOKEN_B = '9876543210:BBHdqTcvCH1vGWJxfSeofSAs0K5PALDsawB';

    /** @var list<array{method: string, token: string, body: array}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.webhook_base_url' => 'https://itcode.uz']);
        $this->sent = [];
        Http::fake(function (HttpRequest $request) {
            preg_match('#/bot([^/]+)/(\w+)$#', $request->url(), $m);
            $this->sent[] = ['method' => $m[2], 'token' => $m[1], 'body' => $request->data()];
            if ($m[1] === 'bad:token') {
                return Http::response(['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized'], 401);
            }

            return Http::response(['ok' => true, 'result' => $m[2] === 'getMe' ? ['id' => 1, 'username' => 'ali_billiard_bot'] : true]);
        });
    }

    /** @return array{0: array, 1: User, 2: TelegramIntegration, 3: string} */
    private function configured(string $token = self::TOKEN_A): array
    {
        $h = $this->hall();
        $owner = $this->tenantUser('CLIENT_OWNER', $h['tenant']);
        $this->actingAs($owner)->putJson('/api/admin/telegram', ['botToken' => $token])->assertOk();
        $integration = $this->asSystem(fn () => TelegramIntegration::query()->where('tenant_id', $h['tenant']->id)->sole());
        $secret = collect($this->sent)->firstWhere('method', 'setWebhook')['body']['secret_token'];

        return [$h, $owner, $integration, $secret];
    }

    private function webhook(TelegramIntegration $i, string $secret, int $chatId, string $text)
    {
        return $this->postJson("/telegram/webhook/{$i->public_id}", [
            'update_id' => random_int(1, 1_000_000),
            'message' => ['message_id' => 1, 'chat' => ['id' => $chatId, 'type' => 'group', 'title' => 'Ali admins'], 'text' => $text],
        ], ['X-Telegram-Bot-Api-Secret-Token' => $secret]);
    }

    private function link(TelegramIntegration $i, string $secret, User $owner, int $chatId): void
    {
        $code = $this->actingAs($owner)->postJson('/api/admin/telegram/link-code')->assertOk()->json('code');
        $this->app['auth']->forgetGuards();
        $this->webhook($i, $secret, $chatId, "/start $code")->assertOk();
    }

    #[Test]
    public function configuring_verifies_the_token_sets_a_secret_webhook_and_stores_the_token_encrypted(): void
    {
        [$h, $owner, $integration, $secret] = $this->configured();

        $setWebhook = collect($this->sent)->firstWhere('method', 'setWebhook');
        $this->assertSame("https://itcode.uz/telegram/webhook/{$integration->public_id}", $setWebhook['body']['url']);
        $this->assertGreaterThanOrEqual(32, strlen($secret));

        $raw = DB::table('telegram_integrations')->where('id', $integration->id)->value('bot_token_encrypted');
        $this->assertStringNotContainsString(self::TOKEN_A, $raw);
        $this->assertSame(self::TOKEN_A, decrypt($raw, false));
        $this->assertSame(hash('sha256', $secret), $integration->webhook_secret_hash);

        $this->actingAs($owner)->putJson('/api/admin/telegram', ['botToken' => 'not-a-token'])->assertStatus(422);
        $this->actingAs($owner)->putJson('/api/admin/telegram', ['botToken' => '1111111111:'.str_repeat('X', 35)])->assertOk();
    }

    #[Test]
    public function item_15_the_token_never_leaves_the_server(): void
    {
        [$h, $owner] = $this->configured();

        $responses = [
            $this->actingAs($owner)->getJson('/api/admin/telegram')->assertOk()->assertJsonPath('botUsername', 'ali_billiard_bot')->getContent(),
            $this->actingAs($owner)->getJson('/api/admin/audit-logs')->getContent(),
            $this->actingAs($this->superAdmin())->getJson("/api/super/tenants/{$h['tenant']->public_id}")->getContent(),
            $this->actingAs($this->superAdmin())->getJson('/api/super/audit-logs')->getContent(),
        ];
        foreach ($responses as $body) {
            $this->assertStringNotContainsString(self::TOKEN_A, $body);
            $this->assertStringNotContainsString('AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsawA', $body);
        }
        $this->assertStringNotContainsString(self::TOKEN_A, $this->asSystem(fn () => AuditLog::query()->get()->toJson()));
        $this->assertStringNotContainsString(self::TOKEN_A, (string) @file_get_contents(storage_path('logs/laravel.log')));
        $this->assertStringNotContainsString(self::TOKEN_A, $this->asSystem(fn () => TelegramIntegration::query()->first()->toJson()));
    }

    #[Test]
    public function webhook_requires_the_secret_and_links_chats_with_a_one_time_code(): void
    {
        [$h, $owner, $integration, $secret] = $this->configured();

        $this->webhook($integration, 'wrong-secret', 111, '/start ABCDEFGH')->assertNotFound();
        $this->postJson("/telegram/webhook/{$integration->public_id}", ['message' => ['chat' => ['id' => 1], 'text' => '/report']])->assertNotFound();

        $code = $this->actingAs($owner)->postJson('/api/admin/telegram/link-code')->assertOk()->assertJsonStructure(['code', 'expiresAt', 'deepLink'])->json('code');
        $this->app['auth']->forgetGuards();
        $this->webhook($integration, $secret, -1001, "/start $code")->assertOk();
        $this->assertSame('Ali admins', $this->asSystem(fn () => TelegramChat::query()->sole()->title));
        $this->assertStringContainsString('ulandi', collect($this->sent)->last()['body']['text']);

        $this->webhook($integration, $secret, -2002, "/start $code")->assertOk(); // reused code
        $this->assertStringContainsString("noto'g'ri", collect($this->sent)->last()['body']['text']);
        $this->assertSame(1, $this->asSystem(fn () => TelegramChat::query()->count()));
    }

    #[Test]
    public function the_bot_only_reports_its_own_tenant(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00'));
        [$a, $ownerA, $integrationA, $secretA] = $this->configured();
        $b = $this->hall();
        foreach ([[$a, 20000], [$b, 99000]] as [$h, $amount]) {
            DB::table('game_sessions')->insert([
                'public_id' => (string) Str::ulid(), 'tenant_id' => $h['tenant']->id, 'branch_id' => $h['branch']->id, 'table_id' => $h['table']->id,
                'status' => 'COMPLETED', 'duration_minutes' => 60, 'start_at' => now()->subHours(2), 'end_at' => now()->subHour(),
                'price_per_hour_snapshot' => 20000, 'rounding_step_snapshot' => 1000, 'amount' => $amount, 'payment_status' => 'PAID',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->link($integrationA, $secretA, $ownerA, 555);

        $this->webhook($integrationA, $secretA, 555, '/report')->assertOk();
        $text = collect($this->sent)->last()['body']['text'];
        $this->assertStringContainsString("20 000 so'm", $text);
        $this->assertStringNotContainsString('99 000', $text);

        $this->webhook($integrationA, $secretA, 777, '/report')->assertOk(); // unlinked chat
        $this->assertStringContainsString('ulanmagan', collect($this->sent)->last()['body']['text']);
    }

    #[Test]
    public function daily_report_is_sent_once_after_the_branch_report_time(): void
    {
        [$h, $owner, $integration, $secret] = $this->configured();
        $this->link($integration, $secret, $owner, 555);
        $this->asSystem(fn () => $h['branch']->forceFill(['report_time' => '23:30:00'])->save());

        $this->travelTo(CarbonImmutable::parse('2026-10-05 23:00:00', 'Asia/Tashkent'));
        $this->artisan('telegram:daily-reports')->assertSuccessful();
        $this->assertSame(0, collect($this->sent)->where('method', 'sendMessage')->filter(fn ($s) => str_contains($s['body']['text'], 'HISOBOT'))->count());

        $this->travelTo(CarbonImmutable::parse('2026-10-05 23:31:00', 'Asia/Tashkent'));
        $this->artisan('telegram:daily-reports')->assertSuccessful();
        $this->artisan('telegram:daily-reports')->assertSuccessful();

        $reports = collect($this->sent)->where('method', 'sendMessage')->filter(fn ($s) => str_contains($s['body']['text'], 'HISOBOT'));
        $this->assertCount(1, $reports);
        $this->assertSame(555, $reports->first()['body']['chat_id']);
        $this->assertStringContainsString('Filial: Markaz', $reports->first()['body']['text']);
        $this->assertSame(self::TOKEN_A, $reports->first()['token']);
    }

    #[Test]
    public function failed_sessions_and_device_outages_alert_linked_chats_once(): void
    {
        [$h, $owner, $integration, $secret] = $this->configured();
        $this->link($integration, $secret, $owner, 555);

        $id = $this->tabletPost($h['token'], '/api/tablet/sessions/prepare', ['tableId' => $h['table']->public_id, 'durationMinutes' => 30])->json('session.id');
        $this->tabletPost($h['token'], "/api/tablet/sessions/$id/start")->assertOk();
        $this->travel(21)->seconds();
        $this->artisan('sessions:finalize');
        $this->artisan('notifications:deliver');

        $this->travel(2)->minutes(); // device stops polling
        $this->artisan('devices:monitor');
        $this->artisan('devices:monitor');
        $this->artisan('notifications:deliver');
        $this->touchDevice($h['device']);
        $this->artisan('devices:monitor');
        $this->artisan('notifications:deliver');

        $texts = collect($this->sent)->where('method', 'sendMessage')->pluck('body.text')->implode("\n");
        $this->assertSame(1, substr_count($texts, 'Sessiya boshlanmadi'));
        $this->assertSame(1, substr_count($texts, 'Qurilma aloqada emas'));
        $this->assertSame(1, substr_count($texts, 'qayta ulandi'));
        $this->assertStringContainsString('javob bermadi', $texts);
        $this->assertSame(3, $this->asSystem(fn () => Notification::query()->where('tenant_id', $h['tenant']->id)->whereIn('type', ['session_failed', 'device_offline', 'device_online'])->count()));
    }

    #[Test]
    public function another_tenant_cannot_manage_or_see_this_integration(): void
    {
        [$h, $owner] = $this->configured();
        $chat = $this->asSystem(function () use ($h) {
            $i = TelegramIntegration::query()->sole();
            $c = new TelegramChat(['integration_id' => $i->id, 'chat_id' => 42]);
            $c->tenant_id = $h['tenant']->id;
            $c->save();

            return $c;
        });
        $other = $this->tenantUser('CLIENT_OWNER');

        $this->actingAs($other)->getJson('/api/admin/telegram')->assertOk()->assertJsonPath('configured', false);
        $this->actingAs($other)->deleteJson("/api/admin/telegram/chats/{$chat->public_id}")->assertNotFound();
        $this->actingAs($this->tenantUser('CLIENT_MANAGER', $h['tenant']))->getJson('/api/admin/telegram')->assertStatus(403);
    }
}
