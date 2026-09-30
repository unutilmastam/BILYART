<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Devices\Models\Device;
use App\Domain\Subscriptions\Models\SubscriptionPayment;
use App\Domain\Tenancy\Enums\SubscriptionStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;

/** spec §41: counts, recorded revenue, device health, recent audit — no customer personal data. */
final class DashboardController extends Controller
{
    public function __invoke(): array
    {
        $counts = ['total' => Tenant::query()->count()];
        foreach (SubscriptionStatus::cases() as $status) {
            $counts[lcfirst(str_replace('_', '', ucwords(strtolower($status->value), '_')))] = Tenant::query()->whereSubscriptionStatus($status)->count();
        }

        $monthStart = now()->startOfMonth();
        $onlineSince = now()->subSeconds((int) config('devices.online_timeout_sec'));

        return [
            'tenants' => $counts,
            'revenue' => [
                'currency' => 'UZS',
                'thisMonth' => (int) SubscriptionPayment::query()->where('paid_at', '>=', $monthStart)->sum('amount'),
                'total' => (int) SubscriptionPayment::query()->sum('amount'),
            ],
            'devices' => [
                'paired' => Device::query()->where('status', 'PAIRED')->count(),
                'online' => Device::query()->where('status', 'PAIRED')->where('last_seen_at', '>=', $onlineSince)->count(),
            ],
            'recentAudit' => AuditLogResource::collection(
                AuditLog::query()
                    ->leftJoin('tenants', 'tenants.id', '=', 'audit_logs.tenant_id')
                    ->select('audit_logs.*', 'tenants.name as tenant_name')
                    ->orderByDesc('audit_logs.id')->limit(10)->get()
            )->resolve(),
        ];
    }
}
