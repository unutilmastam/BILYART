<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Branches\Models\Branch;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Tenancy\Exceptions\TenantContextException;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class TenantScopeTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Tenant, 1: Tenant} */
    private function twoTenantsWithBranches(): array
    {
        return $this->asSystem(function (): array {
            $a = Tenant::factory()->create();
            $b = Tenant::factory()->create();
            Branch::factory()->count(2)->create(['tenant_id' => $a->id]);
            Branch::factory()->count(3)->create(['tenant_id' => $b->id]);

            return [$a, $b];
        });
    }

    #[Test]
    public function without_context_tenant_models_return_nothing(): void
    {
        $this->twoTenantsWithBranches();

        $this->assertSame(0, Branch::query()->count());
    }

    #[Test]
    public function tenant_context_only_sees_its_own_rows(): void
    {
        [$a, $b] = $this->twoTenantsWithBranches();

        $this->assertSame(2, $this->tenantContext()->runAsTenant($a->id, fn () => Branch::query()->count()));
        $this->assertSame(3, $this->tenantContext()->runAsTenant($b->id, fn () => Branch::query()->count()));

        $bBranch = $this->asSystem(fn () => Branch::query()->where('tenant_id', $b->id)->first());
        $this->assertNull($this->tenantContext()->runAsTenant($a->id, fn () => Branch::query()->find($bBranch->id)));
        $this->assertNull($this->tenantContext()->runAsTenant($a->id, fn () => Branch::query()->where('public_id', $bBranch->public_id)->first()));
    }

    #[Test]
    public function creating_assigns_the_context_tenant_and_ignores_nothing_silently(): void
    {
        [$a, $b] = $this->twoTenantsWithBranches();

        $branch = $this->tenantContext()->runAsTenant($a->id, fn () => Branch::query()->create(['name' => 'Yangi']));
        $this->assertSame($a->id, $branch->tenant_id);

        $this->expectException(MassAssignmentException::class);
        $this->tenantContext()->runAsTenant($a->id, fn () => Branch::query()->create(['name' => 'X', 'tenant_id' => $b->id]));
    }

    #[Test]
    public function creating_for_another_tenant_in_tenant_context_is_refused(): void
    {
        [$a, $b] = $this->twoTenantsWithBranches();

        $this->expectException(TenantContextException::class);
        $this->tenantContext()->runAsTenant($a->id, function () use ($b): void {
            $branch = new Branch(['name' => 'X']);
            $branch->tenant_id = $b->id;
            $branch->save();
        });
    }

    #[Test]
    public function creating_without_any_context_is_refused(): void
    {
        $this->expectException(TenantContextException::class);
        (new Branch(['name' => 'X']))->save();
    }

    #[Test]
    public function tenant_id_is_immutable(): void
    {
        [$a, $b] = $this->twoTenantsWithBranches();
        $branch = $this->asSystem(fn () => Branch::query()->where('tenant_id', $a->id)->first());

        $this->expectException(TenantContextException::class);
        $this->asSystem(function () use ($branch, $b): void {
            $branch->tenant_id = $b->id;
            $branch->save();
        });
    }

    #[Test]
    public function no_tenant_owned_model_allows_mass_assigning_tenant_id(): void
    {
        $checked = 0;
        foreach (Finder::create()->files()->in(app_path('Domain'))->path('Models')->name('*.php') as $file) {
            $class = 'App\\Domain\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            if (! in_array(BelongsToTenant::class, class_uses_recursive($class), true)) {
                continue;
            }
            $this->assertFalse((new $class)->isFillable('tenant_id'), "$class allows mass-assigning tenant_id");
            $checked++;
        }
        $this->assertGreaterThan(15, $checked);
    }
}
