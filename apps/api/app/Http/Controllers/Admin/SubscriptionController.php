<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Platform\Services\PlatformSettings;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;

/** GET /api/admin/subscription — allowed even when the subscription is inactive (spec §29: status + contact/payment instructions). */
final class SubscriptionController extends Controller
{
    public function __invoke(TenantContext $context, PlatformSettings $settings): array
    {
        $tenant = Tenant::query()->findOrFail($context->requireTenantId());
        $platform = $context->runAsSystem(fn () => $settings->all());

        return [
            'status' => $tenant->subscriptionStatus()->value,
            'expiresAt' => $tenant->subscription_expires_at?->toIso8601ZuluString(),
            'daysLeft' => $tenant->daysLeft(),
            'limits' => [
                'branchLimit' => $tenant->branch_limit,
                'tableLimit' => $tenant->table_limit,
                'deviceLimit' => $tenant->device_limit,
                'userLimit' => $tenant->user_limit,
            ],
            'supportContact' => $platform['support_contact'],
            'paymentInstructions' => $platform['payment_instructions'],
        ];
    }
}
