<?php

namespace App\Http\Controllers\Telegram;

use App\Domain\Telegram\Models\TelegramIntegration;
use App\Domain\Telegram\Services\TelegramService;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /telegram/webhook/{integration}. Authenticated by Telegram's
 * X-Telegram-Bot-Api-Secret-Token (compared as a hash). The tenant is the
 * integration's — nothing in the update can switch it.
 */
final class WebhookController extends Controller
{
    public function __invoke(Request $request, string $integration, TenantContext $context, TelegramService $telegram): Response
    {
        /** @var TelegramIntegration|null $row */
        $row = $context->runAsSystem(fn () => TelegramIntegration::query()->where('public_id', $integration)->where('is_active', true)->first());
        $secret = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');
        if ($row === null || $row->webhook_secret_hash === null || $secret === '' || ! hash_equals($row->webhook_secret_hash, hash('sha256', $secret))) {
            abort(404);
        }

        $context->runAsTenant($row->tenant_id, function () use ($request, $row, $telegram): void {
            $reply = $telegram->handleUpdate($row, $request->json()->all());
            $chatId = $request->json('message.chat.id');
            if ($reply !== null && $chatId !== null) {
                $telegram->reply($row, (int) $chatId, $reply);
            }
        });

        return response()->noContent(200);
    }
}
