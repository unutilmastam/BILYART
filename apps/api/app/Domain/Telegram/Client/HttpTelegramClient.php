<?php

namespace App\Domain\Telegram\Client;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Real Bot API adapter (https://core.telegram.org/bots/api). The token is only ever used in the URL of outgoing calls. */
final class HttpTelegramClient implements TelegramClient
{
    private const BASE = 'https://api.telegram.org';

    public function getMe(string $token): array
    {
        $result = $this->call($token, 'getMe');

        return ['id' => (int) $result['id'], 'username' => (string) ($result['username'] ?? '')];
    }

    public function setWebhook(string $token, string $url, string $secret): void
    {
        $this->call($token, 'setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => ['message'],
            'drop_pending_updates' => true,
        ]);
    }

    public function deleteWebhook(string $token): void
    {
        $this->call($token, 'deleteWebhook');
    }

    public function sendMessage(string $token, int|string $chatId, string $text): void
    {
        $this->call($token, 'sendMessage', ['chat_id' => $chatId, 'text' => $text, 'disable_web_page_preview' => true]);
    }

    private function call(string $token, string $method, array $params = []): array
    {
        try {
            $response = Http::timeout(10)->connectTimeout(5)->asJson()->post(self::BASE."/bot{$token}/{$method}", $params);
        } catch (ConnectionException) {
            throw new TelegramException('Telegram is unreachable');
        }
        $body = $response->json();
        if (! is_array($body) || ($body['ok'] ?? false) !== true) {
            // Never include the token (it is part of the URL) in the error.
            throw new TelegramException((string) ($body['description'] ?? 'Telegram error'), isset($body['error_code']) ? (int) $body['error_code'] : $response->status());
        }

        return is_array($body['result'] ?? null) ? $body['result'] : [];
    }
}
