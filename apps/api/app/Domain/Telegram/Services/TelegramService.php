<?php

namespace App\Domain\Telegram\Services;

use App\Domain\Audit\Enums\ActorType;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Branches\Models\Branch;
use App\Domain\Notifications\Enums\Severity;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Reports\Services\ReportService;
use App\Domain\Telegram\Client\TelegramClient;
use App\Domain\Telegram\Client\TelegramException;
use App\Domain\Telegram\Models\TelegramChat;
use App\Domain\Telegram\Models\TelegramIntegration;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Per-tenant Telegram bot (spec §24): the bot only ever talks about its own
 * tenant — the tenant is resolved from the integration in the webhook URL,
 * verified by Telegram's secret header. Bot tokens are encrypted at rest and
 * never returned by the API (spec §43.15).
 */
final class TelegramService
{
    public const LINK_CODE_TTL_MIN = 15;

    public function __construct(
        private readonly TelegramClient $client,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly ReportService $reports,
        private readonly NotificationService $notifications,
    ) {}

    public function configure(string $token): TelegramIntegration
    {
        $tenantId = $this->context->requireTenantId();
        try {
            $me = $this->client->getMe($token);
        } catch (TelegramException) {
            throw new ApiException(ErrorCode::VALIDATION_FAILED, [], ['fields' => ['botToken' => ["Bot token noto'g'ri yoki Telegram javob bermadi."]]]);
        }

        /** @var TelegramIntegration $integration */
        $integration = TelegramIntegration::query()->where('tenant_id', $tenantId)->first() ?? new TelegramIntegration; // tenant_id set from context on create
        $secret = Str::random(48);
        $integration->forceFill([
            'bot_token_encrypted' => $token,
            'bot_username' => $me['username'],
            'webhook_secret_hash' => hash('sha256', $secret),
            'is_active' => true,
            'last_error' => null,
            'last_error_at' => null,
        ])->save();

        try {
            $this->client->setWebhook($token, $this->webhookUrl($integration), $secret);
        } catch (TelegramException $e) {
            $integration->forceFill(['last_error' => substr($e->getMessage(), 0, 200), 'last_error_at' => now()])->save();
        }
        $this->audit->log('telegram.configured', $integration, ['bot' => $me['username']]);

        return $integration;
    }

    public function disable(TelegramIntegration $integration): void
    {
        try {
            $this->client->deleteWebhook($integration->bot_token_encrypted);
        } catch (TelegramException) {
            // The token may already be revoked in BotFather; disabling must still work.
        }
        $integration->forceFill(['is_active' => false])->save();
        $this->audit->log('telegram.disabled', $integration);
    }

    /** One-time code the owner sends to the bot as "/start CODE" from the chat to link. */
    public function createLinkCode(TelegramIntegration $integration): array
    {
        $code = strtoupper(Str::random(8));
        $integration->forceFill(['link_code_hash' => hash('sha256', $code), 'link_code_expires_at' => now()->addMinutes(self::LINK_CODE_TTL_MIN)])->save();

        return [
            'code' => $code,
            'expiresAt' => $integration->link_code_expires_at->toIso8601ZuluString(),
            'deepLink' => "https://t.me/{$integration->bot_username}?start={$code}",
        ];
    }

    /** Webhook entry; returns the reply text (or null). The integration was already authenticated. */
    public function handleUpdate(TelegramIntegration $integration, array $update): ?string
    {
        $message = $update['message'] ?? null;
        if (! is_array($message) || ! isset($message['chat']['id'])) {
            return null;
        }
        $chatId = (int) $message['chat']['id'];
        $text = trim((string) ($message['text'] ?? ''));
        $chat = TelegramChat::query()->where('integration_id', $integration->id)->where('chat_id', $chatId)->first();

        if (preg_match('#^/start(?:@\w+)?\s+([A-Za-z0-9]{8})$#', $text, $m)) {
            return $this->link($integration, $chatId, $m[1], (string) ($message['chat']['title'] ?? $message['chat']['username'] ?? $message['chat']['first_name'] ?? ''));
        }
        if (preg_match('#^/report(?:@\w+)?$#', $text)) {
            return $chat ? $this->todayReport($integration, $chat) : __('notifications.not_linked');
        }

        return $chat ? __('notifications.help') : __('notifications.not_linked');
    }

    public function reply(TelegramIntegration $integration, int $chatId, string $text): void
    {
        try {
            $this->client->sendMessage($integration->bot_token_encrypted, $chatId, $text);
        } catch (TelegramException) {
            // Best effort: webhook must return 200 quickly regardless.
        }
    }

    /** Queues each branch's daily report once, at/after its report_time in the branch timezone. */
    public function queueDailyReports(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $count = 0;
        foreach (TelegramIntegration::query()->where('is_active', true)->get() as $integration) {
            $this->context->runAsTenant($integration->tenant_id, function () use ($now, &$count): void {
                foreach (Branch::query()->where('is_active', true)->get() as $branch) {
                    $local = $now->setTimezone($branch->timezone);
                    if ($local->format('H:i:s') < (string) $branch->report_time) {
                        continue;
                    }
                    $date = $local->toDateString();
                    $report = $this->reports->daily(CarbonImmutable::parse($date), $branch, $now)['branches'][0];
                    $sent = $this->notifications->notify($branch->tenant_id, 'daily_report', "daily_report:{$branch->id}:{$date}", [
                        'text' => ReportFormatter::daily($local->format('d.m.Y'), $report),
                        'branchId' => $branch->id,
                    ], Severity::INFO, 'daily_report');
                    $count += $sent ? 1 : 0;
                }
            });
        }

        return $count;
    }

    public function webhookUrl(TelegramIntegration $integration): string
    {
        return rtrim((string) config('services.telegram.webhook_base_url', config('app.url')), '/').'/telegram/webhook/'.$integration->public_id;
    }

    private function link(TelegramIntegration $integration, int $chatId, string $code, string $title): string
    {
        $valid = $integration->link_code_hash !== null
            && hash_equals($integration->link_code_hash, hash('sha256', strtoupper($code)))
            && $integration->link_code_expires_at?->isFuture();
        if (! $valid) {
            return __('notifications.link_invalid');
        }
        $chat = TelegramChat::query()->firstOrNew(['integration_id' => $integration->id, 'chat_id' => $chatId]);
        $chat->forceFill(['tenant_id' => $integration->tenant_id, 'title' => substr($title, 0, 150) ?: null])->save();
        $integration->forceFill(['link_code_hash' => null, 'link_code_expires_at' => null])->save();
        $this->audit->log('telegram.chat_linked', $chat, ['title' => $chat->title], ['tenant_id' => $integration->tenant_id, 'actor_type' => ActorType::SYSTEM, 'actor_id' => null]);

        return __('notifications.linked', ['tenant' => Tenant::query()->findOrFail($integration->tenant_id)->name]);
    }

    private function todayReport(TelegramIntegration $integration, TelegramChat $chat): string
    {
        $branches = Branch::query()->where('is_active', true)
            ->when($chat->branch_id !== null, fn ($q) => $q->whereKey($chat->branch_id))->orderBy('name')->get();
        $parts = [];
        foreach ($branches as $branch) {
            $local = now()->setTimezone($branch->timezone);
            $report = $this->reports->daily(CarbonImmutable::parse($local->toDateString()), $branch)['branches'][0];
            $parts[] = ReportFormatter::daily($local->format('d.m.Y'), $report);
        }

        return $parts === [] ? __('notifications.no_sessions') : implode("\n\n", $parts);
    }
}
