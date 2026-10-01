<?php

namespace Tests\Feature\Subscriptions;

use App\Domain\Branches\Models\Branch;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsSessionFixtures;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/** Semi-automatic subscription billing: per-branch price, receipt upload, Super Admin approve/reject. */
class PaymentRequestTest extends TestCase
{
    use BuildsSessionFixtures, CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** A real PNG "screenshot" (tall, like a phone) — receipts are content-checked and re-encoded. */
    private function receipt(int $w = 600, int $h = 1300): UploadedFile
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 240, 240, 240));
        ob_start();
        imagepng($img);

        return UploadedFile::fake()->createWithContent('chek.png', (string) ob_get_clean());
    }

    private function setPrice(int $price): void
    {
        $this->actingAs($this->superAdmin())->putJson('/api/super/settings', ['pricePerBranch' => $price])->assertOk()->assertJsonPath('pricePerBranch', $price);
        $this->app['auth']->forgetGuards();
    }

    private function send($user, int $months = 1, ?UploadedFile $file = null)
    {
        return $this->actingAs($user)->post('/api/admin/payment-requests', ['months' => $months, 'receipt' => $file ?? $this->receipt(), 'note' => 'Click orqali'], [
            'Accept' => 'application/json', 'Idempotency-Key' => (string) Str::uuid(),
        ]);
    }

    #[Test]
    public function the_owner_pays_per_active_branch_and_the_super_admin_approves(): void
    {
        $h = $this->hall();
        $this->asSystem(function () use ($h): void {
            Branch::factory()->create(['tenant_id' => $h['tenant']->id, 'name' => 'Ikkinchi']);
            Branch::factory()->create(['tenant_id' => $h['tenant']->id, 'name' => 'Yopiq', 'is_active' => false]);
            Tenant::query()->whereKey($h['tenant']->id)->update(['subscription_expires_at' => now()->subDays(3)]); // expired: paying must still work
        });
        $owner = $this->tenantUser('CLIENT_OWNER', $h['tenant']);

        $this->send($owner)->assertStatus(422)->assertJsonPath('error.code', 'BILLING_NOT_CONFIGURED');
        $this->setPrice(100000);

        $this->actingAs($owner)->getJson('/api/admin/subscription')->assertOk()
            ->assertJsonPath('billing.pricePerBranch', 100000)->assertJsonPath('billing.branchCount', 2)
            ->assertJsonPath('billing.monthlyAmount', 200000)->assertJsonPath('billing.pending', null);

        $id = $this->send($owner, 3)->assertCreated()
            ->assertJsonPath('data.status', 'PENDING')->assertJsonPath('data.amount', 600000)->assertJsonPath('data.branchCount', 2)
            ->json('data.id');
        $this->send($owner, 1)->assertStatus(409)->assertJsonPath('error.code', 'PAYMENT_REQUEST_PENDING');
        $this->actingAs($owner)->getJson('/api/admin/subscription')->assertJsonPath('billing.pending.id', $id);

        // Stored privately, re-encoded to JPEG and scaled down; the owner can view it again.
        $row = $this->asSystem(fn () => DB::table('subscription_payment_requests')->where('public_id', $id)->first());
        $this->assertMatchesRegularExpression('#^tenants/'.$h['tenant']->id.'/receipts/[0-9A-Z]{26}\.jpg$#', $row->receipt_path);
        $stored = Storage::disk('local')->get($row->receipt_path);
        $this->assertSame('image/jpeg', (new \finfo(FILEINFO_MIME_TYPE))->buffer($stored));
        $this->actingAs($owner)->get("/api/admin/payment-requests/$id/receipt")->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        // The platform owner is told, then approves after checking the money arrived.
        $this->assertTrue($this->asSystem(fn () => DB::table('notifications')->whereNull('tenant_id')->where('type', 'payment_requested')->exists()));
        $admin = $this->superAdmin();
        $this->actingAs($admin)->getJson('/api/super/payment-requests?status=PENDING')->assertOk()
            ->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.tenant.name', $h['tenant']->name);
        $this->actingAs($admin)->get("/api/super/payment-requests/$id/receipt")->assertOk();
        $this->actingAs($admin)->postJson("/api/super/payment-requests/$id/approve", [], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.status', 'APPROVED');

        $tenant = $this->asSystem(fn () => Tenant::query()->findOrFail($h['tenant']->id));
        $this->assertEqualsWithDelta(now()->addDays(90)->getTimestamp(), $tenant->subscription_expires_at->getTimestamp(), 5); // expired → from today
        $payment = $this->asSystem(fn () => DB::table('subscription_payments')->where('tenant_id', $tenant->id)->sole());
        $this->assertSame(600000, (int) $payment->amount);
        $this->assertSame('CARD_TRANSFER', $payment->method);
        $this->assertSame($payment->id, (int) $this->asSystem(fn () => DB::table('subscription_payment_requests')->where('public_id', $id)->value('payment_id')));
        $this->assertTrue($this->asSystem(fn () => DB::table('notifications')->where('tenant_id', $tenant->id)->where('type', 'payment_approved')->exists()));
        $this->assertTrue($this->asSystem(fn () => DB::table('audit_logs')->where('action', 'subscription.payment_request_approved')->exists()));

        // Never twice.
        $this->actingAs($admin)->postJson("/api/super/payment-requests/$id/approve", [], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(409)->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');
        $this->assertSame(1, $this->asSystem(fn () => DB::table('subscription_payments')->where('tenant_id', $tenant->id)->count()));
    }

    #[Test]
    public function the_super_admin_can_correct_the_amount_or_reject_with_a_reason(): void
    {
        $h = $this->hall();
        $owner = $this->tenantUser('CLIENT_OWNER', $h['tenant']);
        $this->setPrice(50000);
        $admin = $this->superAdmin();

        $first = $this->send($owner)->assertCreated()->json('data.id');
        $this->actingAs($admin)->postJson("/api/super/payment-requests/$first/reject", [], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['reason']]]);
        $this->actingAs($admin)->postJson("/api/super/payment-requests/$first/reject", ['reason' => 'Pul tushmagan'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.status', 'REJECTED')->assertJsonPath('data.rejectReason', 'Pul tushmagan');
        $this->assertTrue($this->asSystem(fn () => DB::table('notifications')->where('tenant_id', $h['tenant']->id)->where('type', 'payment_rejected')->exists()));

        $second = $this->send($owner)->assertCreated()->json('data.id'); // a rejected request no longer blocks a new one
        $this->actingAs($admin)->postJson("/api/super/payment-requests/$second/approve", ['amount' => 45000, 'method' => 'BANK_TRANSFER'], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $payment = $this->asSystem(fn () => DB::table('subscription_payments')->where('tenant_id', $h['tenant']->id)->sole());
        $this->assertSame([45000, 'BANK_TRANSFER'], [(int) $payment->amount, $payment->method]);
    }

    #[Test]
    public function only_the_owner_can_pay_and_only_with_a_real_image_and_a_valid_period(): void
    {
        $h = $this->hall();
        $this->setPrice(100000);
        $owner = $this->tenantUser('CLIENT_OWNER', $h['tenant']);

        $this->send($this->tenantUser('CLIENT_MANAGER', $h['tenant']))->assertForbidden();
        $this->send($this->tenantUser('CLIENT_OPERATOR', $h['tenant']))->assertForbidden();
        $this->send($owner, 2)->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['months']]]);
        $this->send($owner, 1, UploadedFile::fake()->createWithContent('chek.png', '<?php echo 1;'))->assertStatus(422)->assertJsonPath('error.code', 'PHOTO_INVALID');
        $this->assertSame(0, $this->asSystem(fn () => DB::table('subscription_payment_requests')->count()));
        $this->assertSame([], Storage::disk('local')->allFiles('tenants')); // no orphan files on failure

        // The owner may withdraw a pending request, but not an approved one.
        $id = $this->send($owner)->assertCreated()->json('data.id');
        $this->actingAs($owner)->postJson("/api/admin/payment-requests/$id/cancel", [], ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->actingAs($owner)->postJson("/api/admin/payment-requests/$id/cancel", [], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(409);
    }

    #[Test]
    public function another_client_cannot_see_or_touch_the_requests(): void
    {
        $a = $this->hall();
        $b = $this->hall();
        $this->setPrice(100000);
        $ownerA = $this->tenantUser('CLIENT_OWNER', $a['tenant']);
        $ownerB = $this->tenantUser('CLIENT_OWNER', $b['tenant']);
        $id = $this->send($ownerA)->assertCreated()->json('data.id');

        $this->actingAs($ownerB)->getJson('/api/admin/payment-requests')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($ownerB)->get("/api/admin/payment-requests/$id/receipt")->assertNotFound();
        $this->actingAs($ownerB)->postJson("/api/admin/payment-requests/$id/cancel", [], ['Idempotency-Key' => (string) Str::uuid()])->assertNotFound();
        $this->actingAs($ownerB)->postJson("/api/super/payment-requests/$id/approve", [], ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();
        $this->actingAs($ownerB)->getJson('/api/admin/subscription')->assertJsonPath('billing.pending', null);
        $this->actingAs($ownerA)->getJson('/api/admin/payment-requests')->assertJsonCount(1, 'data');
    }
}
