<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Branches\Models\Branch;
use App\Domain\Pricing\Models\PricingPlan;
use App\Domain\Sessions\Models\GameSession;
use App\Domain\Tables\Models\BilliardTable;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Owner's data export (spec §29 "possibly data export"): allowed even when the
 * subscription is inactive. Streams JSON; photos are excluded (personal data,
 * viewable individually), no secrets.
 */
final class ExportController extends Controller
{
    public function __invoke(TenantContext $context, AuditLogger $audit): StreamedResponse
    {
        $tenant = Tenant::query()->findOrFail($context->requireTenantId());
        $audit->log('tenant.exported', $tenant, [], ['tenant_id' => $tenant->id]);
        $filename = 'export-'.now()->format('Ymd-His').'.json';

        // The stream callback runs after the middleware stack has unwound, so the
        // tenant context must be re-established explicitly inside it.
        return response()->streamDownload(fn () => $context->runAsTenant($tenant->id, function () use ($tenant): void {
            $out = fopen('php://output', 'w');
            fwrite($out, '{"tenant":'.json_encode(['name' => $tenant->name, 'exportedAt' => now()->toIso8601ZuluString()], JSON_UNESCAPED_UNICODE));
            $sections = [
                'branches' => fn () => Branch::query()->orderBy('id')->get()->map(fn ($b) => ['id' => $b->public_id, 'name' => $b->name, 'address' => $b->address, 'timezone' => $b->timezone, 'isActive' => $b->is_active]),
                'pricingPlans' => fn () => PricingPlan::query()->orderBy('id')->get()->map(fn ($p) => ['id' => $p->public_id, 'name' => $p->name, 'pricePerHour' => $p->price_per_hour, 'roundingStep' => $p->rounding_step, 'durations' => $p->allowed_durations]),
                'tables' => fn () => BilliardTable::query()->orderBy('id')->get()->map(fn ($t) => ['id' => $t->public_id, 'branchId' => $t->branch_id, 'number' => $t->number, 'name' => $t->name, 'isActive' => $t->is_active]),
            ];
            foreach ($sections as $name => $rows) {
                fwrite($out, ',"'.$name.'":'.json_encode($rows(), JSON_UNESCAPED_UNICODE));
            }
            fwrite($out, ',"sessions":[');
            $first = true;
            GameSession::query()->orderBy('id')->chunkById(500, function ($chunk) use ($out, &$first): void {
                foreach ($chunk as $s) {
                    fwrite($out, ($first ? '' : ',').json_encode([
                        'id' => $s->public_id, 'branchId' => $s->branch_id, 'tableId' => $s->table_id, 'status' => $s->status->value,
                        'startAt' => $s->start_at?->toIso8601ZuluString(), 'endAt' => $s->end_at?->toIso8601ZuluString(), 'endedAt' => $s->ended_at?->toIso8601ZuluString(),
                        'durationMinutes' => $s->duration_minutes, 'amount' => $s->amount, 'paymentStatus' => $s->payment_status->value,
                    ], JSON_UNESCAPED_UNICODE));
                    $first = false;
                }
            });
            fwrite($out, ']}');
            fclose($out);
        }), $filename, ['Content-Type' => 'application/json; charset=utf-8']);
    }
}
