<?php

namespace App\Domain\Auth\Support;

/**
 * RFC 6238 TOTP (HMAC-SHA1, 6 digits, 30 s) — the defaults every authenticator
 * app supports. Pure functions; replay protection lives in TwoFactorService.
 */
final class Totp
{
    public const PERIOD = 30;

    public const DIGITS = 6;

    /** Accepted clock drift in steps on each side. */
    public const WINDOW = 1;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function newSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function step(int $unixTime): int
    {
        return intdiv($unixTime, self::PERIOD);
    }

    public static function code(string $secret, int $step): string
    {
        $binary = self::base32Decode($secret);
        $hash = hash_hmac('sha1', pack('J', $step), $binary, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** Returns the matched step, or null. Steps at or below $afterStep are rejected (already used). */
    public static function match(string $secret, string $code, int $unixTime, ?int $afterStep = null): ?int
    {
        if (! preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $now = self::step($unixTime);
        for ($step = $now - self::WINDOW; $step <= $now + self::WINDOW; $step++) {
            if (($afterStep === null || $step > $afterStep) && hash_equals(self::code($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$account).'?'.http_build_query([
            'secret' => $secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => self::DIGITS, 'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $text): string
    {
        $text = strtoupper(rtrim(str_replace(' ', '', $text), '='));
        $bits = '';
        foreach (str_split($text) as $c) {
            $i = strpos(self::ALPHABET, $c);
            if ($i === false) {
                throw new \InvalidArgumentException('Invalid base32.');
            }
            $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
