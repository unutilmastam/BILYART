<?php

namespace Tests\Feature\Cash;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\Branch;
use App\Domain\Cash\Services\CashPaymentService;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\DeviceCommand;
use App\Domain\Sessions\Models\GameSession;
use App\Domain\Tables\Models\BilliardTable;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsProtocol;
use Tests\Concerns\BuildsSessionFixtures;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/** Bill acceptor payments: the paid cash becomes game time and the server (never the tablet) starts the lamp. */
class CashPaymentTest extends TestCase
{
    use AssertsProtocol, BuildsSessionFixtures, CreatesUsers, RefreshDatabase;

    /** Hall (20 000 so'm/hour) switched to bill acceptor payment, with an online cash box. */
    private function cashHall(): array
    {
        $h = $this->hall();
        $this->asSystem(fn () => Branch::query()->whereKey($h['branch']->id)->update(['payment_mode' => 'BILL_ACCEPTOR']));
        $token = Str::random(40);
        $cash = $this->asSystem(function () use ($h, $token): Device {
            $hw = strtoupper(bin2hex(random_bytes(6)));
            $d = new Device(['firmware_version' => '1.0.0']);
            $d->forceFill([
                'tenant_id' => $h['tenant']->id, 'branch_id' => $h['branch']->id, 'kind' => 'CASH', 'channel_count' => 1,
                'hardware_id' => $hw, 'active_hardware_id' => $hw, 'device_code' => Device::codeFor($hw), 'status' => 'PAIRED',
                'registered_at' => now(), 'paired_at' => now(), 'last_seen_at' => now(), 'token_hash' => hash('sha256', $token),
            ])->save();

            return $d;
        });

        return $h + ['cash' => $cash, 'cashAuth' => ['Authorization' => "Device {$cash->device_code}.{$token}"]];
    }

    private function cashPoll(array $h): TestResponse
    {
        return $this->postJson('/device/v1/cash/poll', ['ts' => now()->getTimestamp(), 'fw' => '1.0.0', 'accepting' => true, 'queued' => 0], $h['cashAuth']);
    }

    private function bill(array $h, int $nominal, ?string $sessionId = null, ?string $uid = null): TestResponse
    {
        return $this->postJson('/device/v1/cash/notes', ['notes' => [[
            'noteUid' => $uid ?? 'N-'.Str::random(10), 'nominal' => $nominal, 'sessionId' => $sessionId, 'deviceTs' => now()->getTimestamp(),
        ]]], $h['cashAuth'] + ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function openPayment(array $h, int $minutes = 60): string
    {
        $id = $this->tabletPost($h['token'], '/api/tablet/sessions/prepare', ['tableId' => $h['table']->public_id, 'durationMinutes' => $minutes])
            ->assertCreated()->json('session.id');
        $this->tabletStart($h['token'], $id)->assertOk()->assertJsonPath('session.status', 'RESERVED')->assertJsonPath('session.payment.accepting', true);

        return $id;
    }

    private function paying(string $id): GameSession
    {
        return $this->asSystem(fn () => GameSession::query()->where('public_id', $id)->sole());
    }

    #[Test]
    public function bills_pay_the_game_and_extra_money_becomes_extra_time(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-07 18:00:00'));
        $h = $this->cashHall();

        $boot = $this->withToken($h['token'])->getJson('/api/tablet/bootstrap')->assertOk()
            ->assertJsonPath('branch.paymentMode', 'BILL_ACCEPTOR')->assertJsonPath('branch.cashOnline', true);
        $this->assertMatchesProtocol('tablet.bootstrap.response', $boot->json());

        $id = $this->tabletPost($h['token'], '/api/tablet/sessions/prepare', ['tableId' => $h['table']->public_id, 'durationMinutes' => 60])
            ->assertCreated()->assertJsonPath('session.amount', 20000)->json('session.id');
        $opened = $this->tabletStart($h['token'], $id)->assertOk()
            ->assertJsonPath('session.status', 'RESERVED')
            ->assertJsonPath('session.payment', ['mode' => 'BILL_ACCEPTOR', 'paid' => 0, 'accepting' => true, 'acceptUntil' => '2026-10-07T18:03:00Z']);
        $this->assertMatchesProtocol('tablet.session', $opened->json());
        $this->assertSame(0, $this->asSystem(fn () => DeviceCommand::query()->count()), 'no lamp before money');

        $poll = $this->cashPoll($h)->assertOk()->assertJsonPath('accept', true)->assertJsonPath('sessionId', $id)
            ->assertJsonPath('required', 20000)->assertJsonPath('paid', 0);
        $this->assertMatchesProtocol('device.cash-poll.response', $poll->json());

        $first = $this->bill($h, 10000, $id, 'BOX-0001')->assertOk()
            ->assertJsonPath('results.0', ['noteUid' => 'BOX-0001', 'status' => 'CREDITED'])->assertJsonPath('paid', 10000)->assertJsonPath('accept', true);
        $this->assertMatchesProtocol('device.cash-notes.response', $first->json());
        $this->withToken($h['token'])->getJson("/api/tablet/sessions/$id")->assertJsonPath('session.payment.paid', 10000);

        // A re-sent bill (flash queue after a lost reply) is never counted twice.
        $this->bill($h, 10000, $id, 'BOX-0001')->assertOk()->assertJsonPath('results.0.status', 'DUPLICATE')->assertJsonPath('paid', 10000);

        // 10 000 + 20 000 = 30 000 → 90 minutes (no change is given: extra money is extra time). The server starts the lamp.
        $this->bill($h, 20000, $id, 'BOX-0002')->assertOk()->assertJsonPath('results.0.status', 'CREDITED')->assertJsonPath('accept', false);
        $s = $this->paying($id);
        $this->assertSame('STARTING', $s->status->value);
        $this->assertSame([90, 30000, 30000, 'PAID'], [$s->duration_minutes, $s->amount, $s->cash_paid, $s->payment_status->value]);
        $this->assertSame('2026-10-07 19:30:00', $s->end_at->format('Y-m-d H:i:s'));
        $command = $this->asSystem(fn () => DeviceCommand::query()->sole());
        $this->assertSame(['START_SESSION', $h['device']->id], [$command->type->value, $command->device_id]);
        $this->cashPoll($h)->assertJsonPath('accept', false)->assertJsonPath('sessionId', null);
        $this->assertSame(2, $this->asSystem(fn () => AuditLog::query()->where('action', 'cash.note_received')->count()));
    }

    #[Test]
    public function a_cancelled_or_abandoned_payment_turns_the_paid_part_into_time_and_nothing_paid_frees_the_table(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-07 18:00:00'));
        $h = $this->cashHall();

        // Customer pays 10 000 of 20 000 and presses "cancel": the acceptor stops at once...
        $id = $this->openPayment($h);
        $this->bill($h, 10000, $id)->assertOk();
        $this->tabletPost($h['token'], "/api/tablet/sessions/$id/cancel")->assertOk()
            ->assertJsonPath('session.status', 'RESERVED')->assertJsonPath('session.payment.accepting', false);
        $this->cashPoll($h)->assertJsonPath('accept', false);
        // ...but a bill already inside the acceptor still counts (grace period)...
        $this->travel(3)->seconds();
        $this->bill($h, 5000, $id)->assertOk()->assertJsonPath('results.0.status', 'CREDITED');
        // ...and after it the paid 15 000 is played: 45 minutes.
        $this->travel(6)->seconds();
        $this->touchDevice($h['device']);
        $this->withToken($h['token'])->getJson("/api/tablet/sessions/$id")->assertJsonPath('session.status', 'STARTING')
            ->assertJsonPath('session.durationMinutes', 45)->assertJsonPath('session.amount', 15000);

        // Nothing paid within 3 minutes: the reservation is cancelled and the table is free again.
        $this->asSystem(fn () => GameSession::query()->whereKey($this->paying($id)->id)->update(['status' => 'COMPLETED', 'ended_at' => now()]));
        $this->touchDevice($h['cash']);
        $other = $this->openPayment($h);
        $this->travel(CashPaymentService::PAY_WINDOW_SEC + CashPaymentService::GRACE_SEC + 1)->seconds();
        $this->touchDevice($h['cash']);
        $this->cashPoll($h)->assertJsonPath('accept', false);
        $this->assertSame('CANCELLED', $this->paying($other)->status->value);
        $this->touchDevice($h['device']);
        $this->withToken($h['token'])->getJson('/api/tablet/tables')->assertJsonPath('tables.0.status', 'AVAILABLE');
    }

    #[Test]
    public function money_that_no_session_can_take_is_kept_for_staff_and_never_lost(): void
    {
        $h = $this->cashHall();
        $id = $this->openPayment($h);
        $this->bill($h, 20000, $id)->assertOk(); // fully paid → started
        $this->assertSame('STARTING', $this->paying($id)->status->value);

        // A second bill arrives late (it was in the acceptor / re-sent after a reconnect).
        $this->bill($h, 5000, $id, 'LATE-1')->assertOk()->assertJsonPath('results.0.status', 'UNASSIGNED');
        $this->bill($h, 1000, null, 'LATE-2')->assertOk()->assertJsonPath('results.0.status', 'UNASSIGNED');
        $this->assertSame(20000, $this->paying($id)->cash_paid);
        $this->assertSame(2, $this->asSystem(fn () => DB::table('notifications')->where('type', 'cash_unassigned')->where('tenant_id', $h['tenant']->id)->count()));

        $owner = $this->tenantUser('CLIENT_OWNER', $h['tenant']);
        $view = $this->actingAs($owner)->getJson('/api/admin/cash')->assertOk()
            ->assertJsonPath('boxes.0.unassigned', ['amount' => 6000, 'count' => 2])
            ->assertJsonPath('boxes.0.uncollected', ['amount' => 26000, 'count' => 3]);
        $late = collect($view->json('notes'))->firstWhere('status', 'UNASSIGNED')['id'];
        $this->actingAs($owner)->postJson("/api/admin/cash/notes/$late/resolve", ['comment' => "Qo'shimcha 15 daqiqa berildi"])->assertOk()->assertJsonPath('data.status', 'RESOLVED');
        $this->actingAs($owner)->postJson("/api/admin/cash/notes/$late/resolve", ['comment' => 'yana'])->assertStatus(409);

        // Emptying the box: everything since the last collection, counted vs expected (mismatch → warning).
        $this->actingAs($owner)->postJson('/api/admin/cash/collections', ['deviceId' => $h['cash']->public_id, 'countedAmount' => 25000, 'comment' => 'kechki'])
            ->assertCreated()->assertJsonPath('data.expected', 26000)->assertJsonPath('data.notesCount', 3);
        $this->assertTrue($this->asSystem(fn () => DB::table('notifications')->where('type', 'cash_mismatch')->exists()));
        $this->actingAs($owner)->getJson('/api/admin/cash')->assertJsonPath('boxes.0.uncollected', ['amount' => 0, 'count' => 0])
            ->assertJsonPath('collections.0.counted', 25000);
        $this->assertTrue($this->asSystem(fn () => AuditLog::query()->where('action', 'cash.collected')->exists()));
    }

    #[Test]
    public function paid_money_whose_lamp_cannot_start_is_reported_not_hidden(): void
    {
        $h = $this->cashHall();
        $id = $this->openPayment($h);
        $this->asSystem(fn () => Device::query()->whereKey($h['device']->id)->update(['last_seen_at' => now()->subMinutes(5)]));

        $this->bill($h, 20000, $id)->assertOk()->assertJsonPath('results.0.status', 'CREDITED');
        $s = $this->paying($id);
        $this->assertSame(['FAILED', 'PAID_NOT_STARTED', 20000, 'PAID'], [$s->status->value, $s->failure_reason, $s->amount, $s->payment_status->value]);
        $this->assertTrue($this->asSystem(fn () => DB::table('notifications')->where('type', 'cash_paid_not_started')->where('severity', 'CRITICAL')->exists()));
        $this->withToken($h['token'])->getJson('/api/tablet/tables')->assertJsonPath('tables.0.status', 'DEVICE_OFFLINE');
    }

    #[Test]
    public function payment_is_refused_while_the_box_is_offline_or_busy_and_device_kinds_stay_apart(): void
    {
        $h = $this->cashHall();

        // Box offline: no payment can open (the customer is told before paying anything).
        $this->asSystem(fn () => Device::query()->whereKey($h['cash']->id)->update(['last_seen_at' => now()->subMinutes(5)]));
        $this->withToken($h['token'])->getJson('/api/tablet/bootstrap')->assertJsonPath('branch.cashOnline', false);
        $id = $this->tabletPost($h['token'], '/api/tablet/sessions/prepare', ['tableId' => $h['table']->public_id, 'durationMinutes' => 60])->json('session.id');
        $this->tabletStart($h['token'], $id)->assertStatus(409)->assertJsonPath('error.code', 'CASH_DEVICE_OFFLINE');
        $this->tabletPost($h['token'], "/api/tablet/sessions/$id/cancel")->assertOk();

        // One customer at a time per acceptor.
        $this->touchDevice($h['cash']);
        $this->openPayment($h);
        $table2 = $this->asSystem(fn () => BilliardTable::factory()->create([
            'tenant_id' => $h['tenant']->id, 'branch_id' => $h['branch']->id, 'number' => 2, 'name' => '2-stol', 'pricing_plan_id' => $h['plan']->id,
        ]));
        $this->wire($table2, $h['device'], 2);
        $second = $this->tabletPost($h['token'], '/api/tablet/sessions/prepare', ['tableId' => $table2->public_id, 'durationMinutes' => 60])->json('session.id');
        $this->tabletStart($h['token'], $second)->assertStatus(409)->assertJsonPath('error.code', 'CASH_BUSY');

        // The bill box cannot pretend to be a lamp controller and vice versa; odd bills are rejected.
        $this->postJson('/device/v1/poll', ['ts' => 1, 'fw' => '1.0.0', 'channels' => [['channel' => 1, 'state' => 'OFF']]], $h['cashAuth'])
            ->assertStatus(403)->assertJsonPath('error.code', 'DEVICE_KIND_MISMATCH');
        $this->bill($h, 3000)->assertStatus(422);

        // A table lamp cannot be wired to the bill box.
        $owner = $this->tenantUser('CLIENT_OWNER', $h['tenant']);
        $this->actingAs($owner)->patchJson("/api/admin/tables/{$table2->public_id}", ['deviceId' => $h['cash']->public_id, 'deviceChannel' => 1])->assertStatus(422);
    }

    #[Test]
    public function a_branch_has_one_bill_acceptor_and_cashier_branches_work_as_before(): void
    {
        $h = $this->cashHall();
        $owner = $this->tenantUser('CLIENT_OWNER', $h['tenant']);
        config(['devices.registration_secret' => 'test-registration-secret-123']);
        $reg = $this->postJson('/device/v1/register', ['hardwareId' => 'AABBCCDDEEFF', 'firmwareVersion' => '1.0.0', 'registrationSecret' => 'test-registration-secret-123', 'kind' => 'CASH'])->assertOk();
        $this->actingAs($owner)->postJson('/api/admin/devices/pair', ['code' => $reg->json('pairingCode'), 'branchId' => $h['branch']->public_id])
            ->assertStatus(409)->assertJsonPath('error.code', 'CASH_DEVICE_EXISTS');
        $this->actingAs($owner)->getJson('/api/admin/devices')->assertJsonFragment(['kind' => 'CASH', 'channels' => []]);

        // Back to cashier: the tablet starts the lamp right away, payment is marked by staff as before.
        $this->actingAs($owner)->patchJson("/api/admin/branches/{$h['branch']->public_id}", ['paymentMode' => 'CASHIER'])->assertOk()->assertJsonPath('data.paymentMode', 'CASHIER');
        $this->app['auth']->forgetGuards();
        $id = $this->tabletPost($h['token'], '/api/tablet/sessions/prepare', ['tableId' => $h['table']->public_id, 'durationMinutes' => 60])->json('session.id');
        $this->tabletStart($h['token'], $id)->assertOk()->assertJsonPath('session.status', 'STARTING')->assertJsonPath('session.payment', null);
        $this->assertSame('UNPAID', $this->paying($id)->payment_status->value);
    }

    #[Test]
    public function another_client_cannot_see_or_touch_the_cash(): void
    {
        $a = $this->cashHall();
        $b = $this->cashHall();
        $id = $this->openPayment($a);

        // B's box cannot credit A's paying session (it only ever sees its own sessions).
        $this->bill($b, 5000, $id)->assertOk()->assertJsonPath('results.0.status', 'UNASSIGNED');
        $this->assertSame(0, $this->paying($id)->cash_paid);

        $this->bill($a, 20000, $id)->assertOk();
        $this->bill($a, 1000, null, 'A-LATE')->assertOk();
        $noteA = $this->asSystem(fn () => DB::table('cash_notes')->where('note_uid', 'A-LATE')->value('public_id'));

        $ownerB = $this->tenantUser('CLIENT_OWNER', $b['tenant']);
        $this->actingAs($ownerB)->getJson('/api/admin/cash')->assertOk()->assertJsonMissing(['id' => $noteA]);
        $this->actingAs($ownerB)->postJson("/api/admin/cash/notes/$noteA/resolve", ['comment' => 'begona'])->assertNotFound();
        $this->actingAs($ownerB)->postJson('/api/admin/cash/collections', ['deviceId' => $a['cash']->public_id, 'countedAmount' => 0])->assertNotFound();

        $operator = $this->tenantUser('CLIENT_OPERATOR', $a['tenant']);
        $this->actingAs($operator)->postJson('/api/admin/cash/collections', ['deviceId' => $a['cash']->public_id, 'countedAmount' => 0])->assertForbidden();
    }
}
