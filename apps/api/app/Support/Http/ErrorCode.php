<?php

namespace App\Support\Http;

/**
 * Stable machine-readable error codes (docs/API.md §4) with their HTTP status.
 * User-facing texts live in lang/<locale>/errors.php under the same key.
 */
enum ErrorCode: string
{
    case UNAUTHENTICATED = 'UNAUTHENTICATED';
    case FORBIDDEN = 'FORBIDDEN';
    case NOT_FOUND = 'NOT_FOUND';
    case METHOD_NOT_ALLOWED = 'METHOD_NOT_ALLOWED';
    case VALIDATION_FAILED = 'VALIDATION_FAILED';
    case RATE_LIMITED = 'RATE_LIMITED';
    case ACCOUNT_LOCKED = 'ACCOUNT_LOCKED';
    case ACCOUNT_DISABLED = 'ACCOUNT_DISABLED';
    case INVALID_CREDENTIALS = 'INVALID_CREDENTIALS';
    case TWO_FACTOR_REQUIRED = 'TWO_FACTOR_REQUIRED';
    case TWO_FACTOR_INVALID = 'TWO_FACTOR_INVALID';
    case SUBSCRIPTION_INACTIVE = 'SUBSCRIPTION_INACTIVE';
    case LIMIT_REACHED = 'LIMIT_REACHED';
    case TABLE_UNAVAILABLE = 'TABLE_UNAVAILABLE';
    case TABLE_DISABLED = 'TABLE_DISABLED';
    case BRANCH_CLOSED = 'BRANCH_CLOSED';
    case DEVICE_OFFLINE = 'DEVICE_OFFLINE';
    case DEVICE_NOT_ASSIGNED = 'DEVICE_NOT_ASSIGNED';
    case PRICING_NOT_CONFIGURED = 'PRICING_NOT_CONFIGURED';
    case DURATION_NOT_ALLOWED = 'DURATION_NOT_ALLOWED';
    case PHOTO_REQUIRED = 'PHOTO_REQUIRED';
    case PHOTO_INVALID = 'PHOTO_INVALID';
    case RESERVATION_EXPIRED = 'RESERVATION_EXPIRED';
    case INVALID_STATE_TRANSITION = 'INVALID_STATE_TRANSITION';
    case CONFLICT = 'CONFLICT';
    case IDEMPOTENCY_KEY_REQUIRED = 'IDEMPOTENCY_KEY_REQUIRED';
    case IDEMPOTENCY_KEY_REUSED = 'IDEMPOTENCY_KEY_REUSED';
    case IDEMPOTENCY_IN_PROGRESS = 'IDEMPOTENCY_IN_PROGRESS';
    case PAIRING_CODE_INVALID = 'PAIRING_CODE_INVALID';
    case PAIRING_CODE_EXPIRED = 'PAIRING_CODE_EXPIRED';
    case DEVICE_ALREADY_PAIRED = 'DEVICE_ALREADY_PAIRED';
    case DEVICE_UNAUTHORIZED = 'DEVICE_UNAUTHORIZED';
    case REPAIR_REQUIRED = 'REPAIR_REQUIRED';
    case DEVICE_REVOKED = 'DEVICE_REVOKED';
    case SERVER_ERROR = 'SERVER_ERROR';

    public function status(): int
    {
        return match ($this) {
            self::UNAUTHENTICATED, self::INVALID_CREDENTIALS, self::TWO_FACTOR_REQUIRED, self::TWO_FACTOR_INVALID, self::DEVICE_UNAUTHORIZED, self::REPAIR_REQUIRED => 401,
            self::SUBSCRIPTION_INACTIVE => 402,
            self::FORBIDDEN, self::ACCOUNT_DISABLED, self::DEVICE_REVOKED => 403,
            self::NOT_FOUND => 404,
            self::METHOD_NOT_ALLOWED => 405,
            self::TABLE_UNAVAILABLE, self::INVALID_STATE_TRANSITION, self::CONFLICT, self::IDEMPOTENCY_KEY_REUSED,
            self::IDEMPOTENCY_IN_PROGRESS, self::DEVICE_ALREADY_PAIRED, self::RESERVATION_EXPIRED => 409,
            self::IDEMPOTENCY_KEY_REQUIRED => 400,
            self::ACCOUNT_LOCKED => 423,
            self::RATE_LIMITED => 429,
            self::SERVER_ERROR => 500,
            default => 422,
        };
    }

    /** Localized user-facing message. */
    public function message(array $replace = []): string
    {
        $key = 'errors.'.$this->value;
        $text = __($key, $replace);

        return $text === $key ? (string) __('errors.SERVER_ERROR') : (string) $text;
    }
}
