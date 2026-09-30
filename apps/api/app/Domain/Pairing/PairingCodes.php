<?php

namespace App\Domain\Pairing;

use Illuminate\Support\Facades\DB;

/**
 * 6-digit pairing codes (spec §17, §37) + one-time poll tokens.
 * Codes are stored as HMAC(app key) so they can be looked up without being
 * readable from the database; a code is unique among live pairings of both
 * devices and tablets. Guessing is limited by rate limits (5 per 15 min).
 */
final class PairingCodes
{
    public const TTL_SEC = 900;

    public static function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    /** @return array{code: string, hash: string} */
    public static function fresh(): array
    {
        for ($i = 0; $i < 20; $i++) {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $hash = self::hash($code);
            $taken = DB::table('device_pairings')->where('code_hash', $hash)->whereNull('used_at')->where('expires_at', '>', now())->exists()
                || DB::table('tablet_pairings')->where('code_hash', $hash)->whereNull('used_at')->where('expires_at', '>', now())->exists();
            if (! $taken) {
                return ['code' => $code, 'hash' => $hash];
            }
        }
        throw new \RuntimeException('Could not allocate a pairing code.');
    }

    /** Random URL-safe secret (32 bytes) used for poll tokens and device/tablet tokens. */
    public static function secret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
