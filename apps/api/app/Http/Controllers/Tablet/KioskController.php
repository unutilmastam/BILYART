<?php

namespace App\Http\Controllers\Tablet;

use App\Domain\Branches\Enums\PaymentMode;
use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Services\WorkingHoursCalendar;
use App\Domain\Devices\Enums\DeviceKind;
use App\Domain\Devices\Enums\DeviceStatus;
use App\Domain\Devices\Models\Device;
use App\Domain\Pricing\Models\PricingPlan;
use App\Domain\Pricing\Services\PriceCalculator;
use App\Domain\Sessions\Services\TableStatusResolver;
use App\Domain\Tables\Models\BilliardTable;
use App\Domain\Tablets\Models\Tablet;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Read side of the kiosk (protocol: tablet.bootstrap / tablet.tables). Only the tablet's own branch. */
final class KioskController extends Controller
{
    public function __construct(
        private readonly TableStatusResolver $statuses,
        private readonly PriceCalculator $prices,
        private readonly TenantSettings $settings,
        private readonly WorkingHoursCalendar $calendar,
    ) {}

    public function bootstrap(Request $request): array
    {
        $tablet = $this->tablet($request);
        $branch = Branch::query()->findOrFail($tablet->branch_id);
        $tenant = Tenant::query()->findOrFail($tablet->tenant_id);
        $settings = $this->settings->for($tenant);

        return [
            'serverTime' => now()->toIso8601ZuluString(),
            'tablet' => ['id' => $tablet->public_id, 'code' => $tablet->device_code],
            'branch' => [
                'id' => $branch->public_id,
                'name' => $branch->name,
                'tenantName' => $tenant->name,
                'timezone' => $branch->timezone,
                'isOpenNow' => $branch->is_active && $this->calendar->isOpenAt($branch, now()->toImmutable()),
                'paymentMode' => $branch->payment_mode->value,
                'cashOnline' => $this->cashOnline($branch),
            ],
            'settings' => [
                'locale' => $settings['locale'],
                'photoRequired' => true, // always: the photo is evidence (kiosk offers no way to skip it)
                'privacyNotice' => $settings['privacy_notice'],
                'warnBeforeSec' => (int) $settings['warn_before_minutes'] * 60,
                'warningAudio' => ['mode' => 'TTS', 'text' => $settings['warning_text']],
            ],
            'tables' => $this->tableRows($tablet, (int) $settings['warn_before_minutes']),
        ];
    }

    public function tables(Request $request): array
    {
        $tablet = $this->tablet($request);
        $branch = Branch::query()->findOrFail($tablet->branch_id);
        $warnMinutes = (int) $this->settings->for(Tenant::query()->findOrFail($tablet->tenant_id))['warn_before_minutes'];

        return [
            'serverTime' => now()->toIso8601ZuluString(),
            'isOpenNow' => $branch->is_active && $this->calendar->isOpenAt($branch, now()->toImmutable()),
            'cashOnline' => $this->cashOnline($branch),
            'tables' => $this->tableRows($tablet, $warnMinutes),
        ];
    }

    /** @return list<array> protocol TabletTable rows */
    private function tableRows(Tablet $tablet, int $warnMinutes): array
    {
        $branch = Branch::query()->findOrFail($tablet->branch_id);

        $tables = BilliardTable::query()->where('branch_id', $tablet->branch_id)->orderBy('number')->get()->each->setRelation('branch', $branch);
        $plans = PricingPlan::query()->whereIn('id', $tables->pluck('pricing_plan_id')->filter())->where('is_active', true)->get()->keyBy('id');
        $resolved = $this->statuses->resolve($tables, $warnMinutes);

        $rows = $tables->map(function (BilliardTable $t) use ($resolved, $plans) {
            $r = $resolved[$t->id];
            $plan = $plans->get($t->pricing_plan_id);

            return [
                'id' => $t->public_id,
                'number' => $t->number,
                'name' => $t->name,
                'status' => $r['status'],
                'session' => $r['session'] ? [
                    'id' => $r['session']->public_id,
                    'status' => $r['session']->status->value,
                    'startAt' => $r['session']->start_at?->toIso8601ZuluString(),
                    'endAt' => $r['session']->end_at?->toIso8601ZuluString(),
                ] : null,
                'pricing' => $plan ? [
                    'type' => $plan->type->value,
                    'pricePerHour' => $plan->price_per_hour,
                    'durations' => $this->prices->quotes($plan),
                ] : null,
            ];
        })->values()->all();

        return $rows;
    }

    /** Bill acceptor reachable (null when the branch pays at the cashier). */
    private function cashOnline(Branch $branch): ?bool
    {
        if ($branch->payment_mode !== PaymentMode::BILL_ACCEPTOR) {
            return null;
        }
        $cash = Device::query()->where('branch_id', $branch->id)->where('kind', DeviceKind::CASH->value)->where('status', DeviceStatus::PAIRED->value)->first();

        return $cash !== null && $cash->isOnline();
    }

    public function heartbeat(Request $request): Response
    {
        $data = $request->validate(['appVersion' => ['nullable', 'string', 'max:32']]);
        $this->tablet($request)->forceFill(['last_seen_at' => now(), 'last_ip' => $request->ip(), 'app_version' => $data['appVersion'] ?? null])->save();

        return response()->noContent();
    }

    private function tablet(Request $request): Tablet
    {
        return $request->attributes->get('tablet');
    }
}
