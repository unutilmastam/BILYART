<?php

namespace App\Support\Logging;

/** Removes secrets from arrays before they reach logs or the audit trail (spec §43.13). */
final class Redactor
{
    public const MASK = '[REDACTED]';

    private const SENSITIVE = [
        'password', 'password_confirmation', 'current_password', 'currentpassword', 'new_password',
        'token', 'access_token', 'refresh_token', 'polltoken', 'poll_token', 'bot_token', 'bottoken',
        'bot_token_encrypted', 'authorization', 'cookie', 'secret', 'registrationsecret', 'registration_secret',
        'api_key', 'apikey', 'x-xsrf-token', 'x-csrf-token', '_token', 'pairingcode', 'pairing_code',
    ];

    public static function isSensitive(string $key): bool
    {
        $normalized = strtolower($key);

        return in_array($normalized, self::SENSITIVE, true)
            || str_contains($normalized, 'password')
            || str_contains($normalized, 'secret')
            || str_ends_with($normalized, 'token');
    }

    public static function redact(array $data, int $depth = 0): array
    {
        if ($depth > 8) {
            return ['_truncated' => true];
        }
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitive($key)) {
                $data[$key] = self::MASK;
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value, $depth + 1);
            }
        }

        return $data;
    }
}
