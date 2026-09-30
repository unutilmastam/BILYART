<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Branches\Models\Branch;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Telegram\Models\TelegramChat;
use App\Domain\Telegram\Models\TelegramIntegration;
use App\Domain\Telegram\Services\TelegramService;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/** Client Admin → Telegram (spec §24). The bot token is write-only. */
final class TelegramController extends Controller
{
    public function __construct(
        private readonly TelegramService $telegram,
        private readonly TenantContext $context,
    ) {}

    public function show(): array
    {
        $integration = $this->integration(false);

        return $this->present($integration);
    }

    public function update(Request $request): array
    {
        $data = $request->validate(['botToken' => ['required', 'string', 'max:100', 'regex:/^\d{5,15}:[A-Za-z0-9_-]{30,60}$/']]);

        return $this->present($this->telegram->configure($data['botToken']));
    }

    public function destroy(): Response
    {
        $this->telegram->disable($this->integration());

        return response()->noContent();
    }

    public function linkCode(): array
    {
        return $this->telegram->createLinkCode($this->integration());
    }

    public function updateChat(Request $request, TelegramChat $chat): array
    {
        $data = $request->validate([
            'branchId' => ['sometimes', 'nullable', 'string', 'size:26'],
            'receivesDailyReport' => ['sometimes', 'boolean'],
            'receivesAlerts' => ['sometimes', 'boolean'],
        ]);
        $changes = [];
        if (array_key_exists('branchId', $data)) {
            $changes['branch_id'] = $data['branchId'] === null ? null
                : (Branch::query()->where('public_id', $data['branchId'])->value('id') ?? throw new ApiException(ErrorCode::VALIDATION_FAILED, [], ['fields' => ['branchId' => ['Filial topilmadi.']]]));
        }
        foreach (['receivesDailyReport' => 'receives_daily_report', 'receivesAlerts' => 'receives_alerts'] as $in => $col) {
            if (array_key_exists($in, $data)) {
                $changes[$col] = (bool) $data[$in];
            }
        }
        $chat->forceFill($changes)->save();

        return $this->present($this->integration());
    }

    public function destroyChat(TelegramChat $chat): Response
    {
        $chat->delete();

        return response()->noContent();
    }

    public function test(NotificationService $notifications): Response
    {
        $integration = $this->integration();
        $notifications->notify($integration->tenant_id, 'telegram_test', 'telegram_test:'.$integration->id.':'.Str::ulid(), ['text' => __('notifications.test')]);
        $notifications->deliverPending();

        return response()->noContent();
    }

    private function integration(bool $required = true): ?TelegramIntegration
    {
        $integration = TelegramIntegration::query()->where('tenant_id', $this->context->requireTenantId())->first();
        if ($required && ($integration === null || ! $integration->is_active)) {
            throw ApiException::of(ErrorCode::NOT_FOUND);
        }

        return $integration;
    }

    private function present(?TelegramIntegration $i): array
    {
        if ($i === null) {
            return ['configured' => false, 'isActive' => false, 'botUsername' => null, 'lastError' => null, 'chats' => []];
        }
        $branches = Branch::query()->pluck('public_id', 'id');

        return [
            'configured' => true,
            'isActive' => $i->is_active,
            'botUsername' => $i->bot_username,
            'lastError' => $i->last_error,
            'lastErrorAt' => $i->last_error_at?->toIso8601ZuluString(),
            'chats' => TelegramChat::query()->where('integration_id', $i->id)->orderBy('id')->get()->map(fn (TelegramChat $c) => [
                'id' => $c->public_id,
                'title' => $c->title,
                'branchId' => $c->branch_id ? $branches[$c->branch_id] ?? null : null,
                'receivesDailyReport' => $c->receives_daily_report,
                'receivesAlerts' => $c->receives_alerts,
            ])->values(),
        ];
    }
}
