<?php

namespace App\Support\Http;

use RuntimeException;

/** Expected business error rendered as `{error:{code,message}}` (never a stack trace). */
class ApiException extends RuntimeException
{
    /** @param array<string, string|int> $replace message placeholders */
    public function __construct(
        public readonly ErrorCode $errorCode,
        public readonly array $replace = [],
        public readonly array $extra = [],
    ) {
        parent::__construct($errorCode->value);
    }

    public static function of(ErrorCode $code, array $replace = []): self
    {
        return new self($code, $replace);
    }
}
