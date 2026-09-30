<?php

namespace App\Domain\Telegram\Client;

use RuntimeException;

final class TelegramException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $errorCode = null)
    {
        parent::__construct($message);
    }
}
