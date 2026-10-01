<?php

namespace App\Domain\Subscriptions\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Branches\Models\Branch;
use App\Domain\Notifications\Enums\Severity;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Photos\Services\ImageSanitizer;
use App\Domain\Photos\Storage\PhotoStorage;
use App\Domain\Platform\Services\PlatformSettings;
use App\Domain\Subscriptions\Enums\PaymentMethod;
use App\Domain\Subscriptions\Enums\PaymentRequestStatus;
use App\Domain\Subscriptions\Models\SubscriptionPaymentRequest;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Users\Models\User;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Semi-automatic subscription billing (owner decision 2026-10-01, no payment gateway yet):
 * the client owner reports a transfer with a receipt photo, the Super Admin checks the money
 * arrived and approves (→ SubscriptionService::recordPayment, i.e. the same audited path as a
 * manual payment) or rejects. Nothing is marked paid without a human confirming it.
 * Price = active branches × price_per_branch × months, snapshotted on the request.
 */
final class PaymentRequestService
{
    public const DAYS_PER_MONTH = 30;

    public const MONTH_OPTIONS = [1, 3, 6, 12];

    public function __construct(
        private readonly TenantContext $context,
        private readonly PlatformSettings $settings,
        private readonly SubscriptionService $subscriptions,
        private readonly PhotoStorage $storage,
        private readonly ImageSanitizer $sanitizer,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{pricePerBranch: int, branchCount: int, monthlyAmount: int} for the current tenant */
    public function quote(): array
    {
        $price = (int) $this->context->runAsSystem(fn () => $this->settings->get('price_per_branch'));
        $branches = max(1, Branch::query()->where('is_active', true)->count());

        return ['pricePerBranch' => $price, 'branchCount' => $branches, 'monthlyAmount' => $price * $branches];
    }

    public function create(User $owner, int $months, string $receipt, ?string $note): SubscriptionPaymentRequest
    {
        if (! in_array($months, self::MONTH_OPTIONS, true)) {
            throw new ApiException(ErrorCode::VALIDATION_FAILED, [], ['fields' => ['months' => [__('validation.in', ['attribute' => 'months'])]]]);
        }
        $quote = $this->quote();
        if ($quote['pricePerBranch'] <= 0) {
            throw ApiException::of(ErrorCode::BILLING_NOT_CONFIGURED);
        }
        $clean = $this->sanitizer->sanitizeDocument($receipt);
        $tenantId = $this->context->requireTenantId();
        $path = sprintf('tenants/%d/receipts/%s.jpg', $tenantId, strtoupper((string) Str::ulid()));
        $this->storage->put($path, $clean['bytes']);

        try {
            $request = DB::transaction(function () use ($owner, $months, $note, $quote, $path, $clean, $tenantId): SubscriptionPaymentRequest {
                // One open request per client: lock the tenant row so two taps cannot both pass the check.
                $this->context->runAsSystem(fn () => Tenant::query()->lockForUpdate()->findOrFail($tenantId));
                if (SubscriptionPaymentRequest::query()->where('status', PaymentRequestStatus::PENDING->value)->exists()) {
                    throw ApiException::of(ErrorCode::PAYMENT_REQUEST_PENDING);
                }
                $request = new SubscriptionPaymentRequest([
                    'months' => $months,
                    'branch_count' => $quote['branchCount'],
                    'price_per_branch' => $quote['pricePerBranch'],
                    'amount' => $quote['monthlyAmount'] * $months,
                    'note' => $note,
                ]);
                $request->forceFill([
                    'status' => PaymentRequestStatus::PENDING,
                    'receipt_path' => $path,
                    'receipt_sha256' => hash('sha256', $clean['bytes']),
                    'created_by' => $owner->id,
                ])->save();
                $this->audit->log('subscription.payment_requested', $request, ['amount' => $request->amount, 'months' => $months, 'branches' => $quote['branchCount']]);

                return $request;
            });
        } catch (\Throwable $e) {
            $this->storage->delete($path); // no orphan receipts
            throw $e;
        }

        $tenantName = $this->context->runAsSystem(fn () => Tenant::query()->whereKey($tenantId)->value('name'));
        $this->notifications->notify(null, 'payment_requested', 'payment_requested:'.$request->id, [
            'text' => sprintf("%s: obuna to'lovi so'rovi — %s so'm, %d oy. Tekshirib tasdiqlang.", $tenantName, number_format($request->amount, 0, '.', ' '), $months),
        ], Severity::INFO, null);

        return $request;
    }

    public function cancel(SubscriptionPaymentRequest $request): SubscriptionPaymentRequest
    {
        return DB::transaction(function () use ($request): SubscriptionPaymentRequest {
            /** @var SubscriptionPaymentRequest $locked */
            $locked = SubscriptionPaymentRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($locked->status !== PaymentRequestStatus::PENDING) {
                throw ApiException::of(ErrorCode::INVALID_STATE_TRANSITION);
            }
            $locked->forceFill(['status' => PaymentRequestStatus::CANCELLED])->save();
            $this->audit->log('subscription.payment_request_cancelled', $locked, ['amount' => $locked->amount]);

            return $locked;
        });
    }

    /** Super Admin confirmed the money arrived: record the payment and extend by months × 30 days. */
    public function approve(SubscriptionPaymentRequest $request, User $admin, ?int $amount, PaymentMethod $method): SubscriptionPaymentRequest
    {
        return $this->context->runAsSystem(fn () => DB::transaction(function () use ($request, $admin, $amount, $method): SubscriptionPaymentRequest {
            /** @var SubscriptionPaymentRequest $locked */
            $locked = SubscriptionPaymentRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($locked->status !== PaymentRequestStatus::PENDING) {
                throw ApiException::of(ErrorCode::INVALID_STATE_TRANSITION);
            }
            /** @var Tenant $tenant */
            $tenant = Tenant::query()->findOrFail($locked->tenant_id);
            $paid = $amount ?? $locked->amount;
            $subscription = $this->subscriptions->recordPayment(
                $tenant, $paid, $method, $locked->months * self::DAYS_PER_MONTH, $admin, null,
                "So'rov {$locked->public_id}".($locked->note ? ': '.$locked->note : ''),
            );
            $locked->forceFill([
                'status' => PaymentRequestStatus::APPROVED,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'payment_id' => $subscription->payment_id,
            ])->save();
            $this->audit->log('subscription.payment_request_approved', $locked, ['amount' => $paid, 'months' => $locked->months, 'expiresAt' => $subscription->expires_at->toIso8601ZuluString()], ['tenant_id' => $locked->tenant_id]);
            $this->notifications->notify($locked->tenant_id, 'payment_approved', 'payment_approved:'.$locked->id, [
                'text' => sprintf("To'lovingiz tasdiqlandi: %s so'm. Obuna %s gacha uzaytirildi.", number_format($paid, 0, '.', ' '), $subscription->expires_at->setTimezone('Asia/Tashkent')->format('d.m.Y')),
            ]);

            return $locked;
        }));
    }

    public function reject(SubscriptionPaymentRequest $request, User $admin, string $reason): SubscriptionPaymentRequest
    {
        return $this->context->runAsSystem(fn () => DB::transaction(function () use ($request, $admin, $reason): SubscriptionPaymentRequest {
            /** @var SubscriptionPaymentRequest $locked */
            $locked = SubscriptionPaymentRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($locked->status !== PaymentRequestStatus::PENDING) {
                throw ApiException::of(ErrorCode::INVALID_STATE_TRANSITION);
            }
            $locked->forceFill([
                'status' => PaymentRequestStatus::REJECTED,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'reject_reason' => $reason,
            ])->save();
            $this->audit->log('subscription.payment_request_rejected', $locked, ['amount' => $locked->amount, 'reason' => $reason], ['tenant_id' => $locked->tenant_id]);
            $this->notifications->notify($locked->tenant_id, 'payment_rejected', 'payment_rejected:'.$locked->id, [
                'text' => "To'lov so'rovingiz rad etildi: {$reason}",
            ], Severity::WARNING);

            return $locked;
        }));
    }

    public function receipt(SubscriptionPaymentRequest $request): string
    {
        return $this->storage->get($request->receipt_path) ?? throw ApiException::of(ErrorCode::NOT_FOUND);
    }
}
