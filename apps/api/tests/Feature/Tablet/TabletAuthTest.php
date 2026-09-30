<?php

namespace Tests\Feature\Tablet;

use App\Domain\Branches\Models\Branch;
use App\Domain\Tablets\Models\Tablet;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TabletAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware(['api', 'auth.tablet'])->get('/_test/tablet/branches', fn () => [
            'tenant' => app(TenantContext::class)->tenantId(),
            'branches' => Branch::query()->pluck('name'),
        ]);
    }

    private function pairedTablet(string $token, string $status = 'PAIRED'): Tablet
    {
        return $this->asSystem(function () use ($token, $status): Tablet {
            $tenant = Tenant::factory()->create();
            $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'name' => 'T'.$tenant->id]);
            $tablet = new Tablet(['name' => 'Kiosk']);
            $tablet->forceFill([
                'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'device_code' => 'TABLET-'.strtoupper(Str::random(6)),
                'status' => $status, 'token_hash' => hash('sha256', $token), 'registered_at' => now(), 'paired_at' => now(),
            ])->save();

            return $tablet;
        });
    }

    #[Test]
    public function a_paired_tablet_acts_only_within_its_tenant(): void
    {
        $token = str_repeat('a', 43);
        $tablet = $this->pairedTablet($token);
        $this->pairedTablet(str_repeat('b', 43)); // another tenant

        $this->withToken($token)->getJson('/_test/tablet/branches')
            ->assertOk()->assertJsonPath('tenant', $tablet->tenant_id)->assertJsonPath('branches', ['T'.$tablet->tenant_id]);
    }

    #[Test]
    public function unknown_revoked_or_missing_tokens_are_rejected(): void
    {
        $this->pairedTablet(str_repeat('r', 43), 'REVOKED');

        $this->getJson('/_test/tablet/branches')->assertStatus(401);
        $this->withToken(str_repeat('x', 43))->getJson('/_test/tablet/branches')->assertStatus(401);
        $this->withToken(str_repeat('r', 43))->getJson('/_test/tablet/branches')->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }
}
