<?php

namespace App\Domain\Subscriptions\Services;

use App\Domain\Audit\Enums\ActorType;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Notifications\Enums\Severity;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Platform\Services\PlatformSettings;
use App\Domain\Subscriptions\Enums\SubscriptionEventType;
use App\Domain\Subscriptions\Models\SubscriptionEvent;
use App\Domain\Tenancy\Enums\TenantStatusFlag;
use App\Domain\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;

/**
 * Subscription reminders and expiry (spec §4, §40). Runs hourly; every
 * message is deduplicated by (tenant, expiry instant, reminder day), so an
 * extension restarts the reminder cycle and nothing is sent twice.
 * Expiry never deletes data — it only locks business routes (402).
 */
final class SubscriptionMonitor
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly PlatformSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{reminders: int, expired: int} */
    public function check(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $reminderDays = array_map('intval', (array) $this->settings->get('reminder_days'));
        $reminders = 0;
        $expired = 0;

        $tenants = Tenant::query()->where('status_flag', TenantStatusFlag::ACTIVE->value)->whereNotNull('subscription_expires_at')
            ->where('subscription_expires_at', '<=', $now->addDays(max($reminderDays ?: [5]) + 1))->get();

        foreach ($tenants as $tenant) {
            $expiresAt = $tenant->subscription_expires_at;
            $stamp = $expiresAt->getTimestamp();

            if ($expiresAt->lessThanOrEqualTo($now)) {
                $sent = $this->notifications->notify($tenant->id, 'subscription_expired', "sub_expired:{$tenant->id}:{$stamp}", [
                    'text' => __('notifications.subscription_expired'),
                ], Severity::CRITICAL);
                if ($sent !== null) {
                    $event = new SubscriptionEvent(['type' => SubscriptionEventType::EXPIRED, 'new_value' => ['expiresAt' => $expiresAt->toIso8601ZuluString()]]);
                    $event->tenant_id = $tenant->id;
                    $event->save();
                    $this->audit->log('subscription.expired', $tenant, ['expiresAt' => $expiresAt->toIso8601ZuluString()], [
                        'tenant_id' => $tenant->id, 'actor_type' => ActorType::SYSTEM, 'actor_id' => null,
                    ]);
                    $this->platform("sub_expired:{$tenant->id}:{$stamp}", "Mijoz obunasi tugadi: {$tenant->name}", Severity::WARNING);
                    $expired++;
                }

                continue;
            }

            // Calendar days left in the tenant's timezone (0 = expires later today).
            $today = $now->setTimezone($tenant->timezone)->startOfDay();
            $expiryDay = $expiresAt->setTimezone($tenant->timezone)->startOfDay();
            $daysLeft = (int) $today->diffInDays($expiryDay);
            if (! in_array($daysLeft, $reminderDays, true)) {
                continue;
            }
            $text = $daysLeft === 0
                ? __('notifications.subscription_expires_today')
                : __('notifications.subscription_expiring', ['days' => $daysLeft, 'date' => $expiresAt->setTimezone($tenant->timezone)->format('d.m.Y')]);
            if ($this->notifications->notify($tenant->id, 'subscription_expiring', "sub_reminder:{$tenant->id}:{$stamp}:{$daysLeft}", [
                'text' => $text, 'daysLeft' => $daysLeft,
            ], $daysLeft <= 1 ? Severity::WARNING : Severity::INFO) !== null) {
                $this->platform("sub_reminder:{$tenant->id}:{$stamp}:{$daysLeft}", "{$tenant->name}: obuna {$daysLeft} kundan keyin tugaydi", Severity::INFO);
                $reminders++;
            }
        }

        return ['reminders' => $reminders, 'expired' => $expired];
    }

    /** In-app notification for the Super Admin (tenant_id NULL). */
    private function platform(string $key, string $text, Severity $severity): void
    {
        $this->notifications->notify(null, 'platform', 'platform:'.$key, ['text' => $text], $severity, null);
    }
}
