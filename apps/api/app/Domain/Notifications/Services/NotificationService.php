<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\Severity;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Models\NotificationLog;
use App\Domain\Telegram\Client\TelegramClient;
use App\Domain\Telegram\Client\TelegramException;
use App\Domain\Telegram\Models\TelegramChat;
use App\Domain\Telegram\Models\TelegramIntegration;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Database-backed notifications (spec §40). `dedupe_key` is UNIQUE, so the
 * same event is never notified twice, even if cron runs overlap. Telegram
 * delivery happens in `notifications:deliver` (scheduler), with a log row per
 * chat; in-app notifications are the rows themselves.
 */
final class NotificationService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TelegramClient $telegram,
    ) {}

    /**
     * @param  array{text: string, branchId?: ?int}  $payload  text is the rendered Uzbek message
     * @param  'alerts'|'daily_report'|null  $telegramKind  null = in-app only
     */
    public function notify(?int $tenantId, string $type, string $dedupeKey, array $payload, Severity $severity = Severity::INFO, ?string $telegramKind = 'alerts'): ?Notification
    {
        return $this->context->runAsSystem(function () use ($tenantId, $type, $dedupeKey, $payload, $severity, $telegramKind): ?Notification {
            $inserted = DB::table('notifications')->insertOrIgnore([
                'public_id' => (string) Str::ulid(),
                'tenant_id' => $tenantId,
                'type' => $type,
                'severity' => $severity->value,
                'dedupe_key' => substr($dedupeKey, 0, 191),
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);
            if ($inserted === 0) {
                return null; // already notified
            }
            /** @var Notification $notification */
            $notification = Notification::query()->where('dedupe_key', substr($dedupeKey, 0, 191))->sole();
            $this->log($notification, NotificationChannel::IN_APP, null, 'SENT');

            if ($tenantId !== null && $telegramKind !== null) {
                foreach ($this->chatsFor($tenantId, $telegramKind, $payload['branchId'] ?? null) as $chat) {
                    $this->log($notification, NotificationChannel::TELEGRAM, $chat->public_id, 'PENDING');
                }
            }

            return $notification;
        });
    }

    /** Sends pending Telegram messages. Failures are recorded (never the token). */
    public function deliverPending(int $limit = 100): int
    {
        return $this->context->runAsSystem(function () use ($limit): int {
            $sent = 0;
            $logs = NotificationLog::query()->where('channel', NotificationChannel::TELEGRAM->value)->where('status', 'PENDING')
                ->orderBy('id')->limit($limit)->get();
            foreach ($logs as $log) {
                $notification = Notification::query()->find($log->notification_id);
                $chat = TelegramChat::query()->where('public_id', $log->target)->first();
                $integration = $chat ? TelegramIntegration::query()->find($chat->integration_id) : null;
                if ($notification === null || $chat === null || $integration === null || ! $integration->is_active) {
                    $log->forceFill(['status' => 'SKIPPED'])->save();

                    continue;
                }
                try {
                    $this->telegram->sendMessage($integration->bot_token_encrypted, $chat->chat_id, (string) ($notification->payload['text'] ?? ''));
                    $log->forceFill(['status' => 'SENT', 'sent_at' => now()])->save();
                    $sent++;
                } catch (TelegramException $e) {
                    $log->forceFill(['status' => 'FAILED', 'error' => substr($e->getMessage(), 0, 200)])->save();
                    $integration->forceFill(['last_error' => substr($e->getMessage(), 0, 200), 'last_error_at' => now()])->save();
                    Log::warning('TELEGRAM_SEND_FAILED', ['integration' => $integration->public_id, 'code' => $e->errorCode]);
                }
            }

            return $sent;
        });
    }

    /** @return iterable<TelegramChat> */
    private function chatsFor(int $tenantId, string $kind, ?int $branchId): iterable
    {
        $integration = TelegramIntegration::query()->where('tenant_id', $tenantId)->where('is_active', true)->first();
        if ($integration === null) {
            return [];
        }

        return TelegramChat::query()
            ->where('integration_id', $integration->id)
            ->where($kind === 'daily_report' ? 'receives_daily_report' : 'receives_alerts', true)
            ->where(fn ($q) => $branchId === null ? $q : $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
            ->get();
    }

    private function log(Notification $notification, NotificationChannel $channel, ?string $target, string $status): void
    {
        $log = new NotificationLog(['notification_id' => $notification->id, 'channel' => $channel, 'target' => $target, 'status' => $status, 'sent_at' => $status === 'SENT' ? now() : null]);
        $log->save();
    }
}
