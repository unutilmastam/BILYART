<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Platform\Services\PlatformSettings;
use App\Domain\Subscriptions\Enums\PaymentRequestStatus;
use App\Domain\Subscriptions\Models\SubscriptionPaymentRequest;
use App\Domain\Subscriptions\Services\PaymentRequestService;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentRequestResource;

/** GET /api/admin/subscription — allowed even when the subscription is inactive (spec §29: status + contact/payment instructions). */
final class SubscriptionController extends Controller
{
    public function __invoke(TenantContext $context, PlatformSettings $settings, PaymentRequestService $requests): array
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
            // Per-branch pricing for the "pay" form; amounts are integer UZS.
            'billing' => $requests->quote() + [
                'monthOptions' => PaymentRequestService::MONTH_OPTIONS,
                'pending' => ($p = SubscriptionPaymentRequest::query()->where('status', PaymentRequestStatus::PENDING->value)->first()) ? new PaymentRequestResource($p) : null,
            ],
        ];
    }
}
