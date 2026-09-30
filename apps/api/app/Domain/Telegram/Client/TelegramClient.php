<?php

namespace App\Domain\Telegram\Client;

/** Telegram Bot API port. Production adapter: HttpTelegramClient. Tests fake the HTTP layer only. */
interface TelegramClient
{
    /** @return array{id: int, username: string} bot identity; throws TelegramException on invalid token */
    public function getMe(string $token): array;

    public function setWebhook(string $token, string $url, string $secret): void;

    public function deleteWebhook(string $token): void;

    public function sendMessage(string $token, int|string $chatId, string $text): void;
}
