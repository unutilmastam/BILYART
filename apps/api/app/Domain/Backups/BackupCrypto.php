<?php

namespace App\Domain\Backups;

use RuntimeException;

/**
 * Streaming authenticated encryption for backup files: AES-256-GCM per 1 MiB
 * chunk, random 96-bit IV per chunk, chunk index bound as AAD (no reordering),
 * final chunk flagged (no truncation). Key = BACKUP_ENCRYPTION_KEY (base64, 32 bytes).
 *
 * File: "BLYBK1" | repeated [u32 length][u8 final][12 iv][16 tag][ciphertext]
 */
final class BackupCrypto
{
    private const MAGIC = 'BLYBK1';

    private const CHUNK = 1048576;

    public function __construct(private readonly string $key)
    {
        if (strlen($key) !== 32) {
            throw new RuntimeException('BACKUP_ENCRYPTION_KEY must be 32 bytes (base64-encoded).');
        }
    }

    public static function fromConfig(): self
    {
        $raw = (string) config('backup.encryption_key');
        $key = str_starts_with($raw, 'base64:') ? base64_decode(substr($raw, 7), true) : base64_decode($raw, true);

        return new self($key === false ? '' : $key);
    }

    public function encryptFile(string $in, string $out): void
    {
        $src = fopen($in, 'rb');
        $dst = fopen($out, 'wb');
        fwrite($dst, self::MAGIC);
        $index = 0;
        $chunk = fread($src, self::CHUNK);
        do {
            $next = fread($src, self::CHUNK);
            $final = $next === '' || $next === false;
            $iv = random_bytes(12);
            $tag = '';
            $ct = openssl_encrypt((string) $chunk, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, $this->aad($index, $final));
            if ($ct === false) {
                throw new RuntimeException('Encryption failed.');
            }
            fwrite($dst, pack('N', strlen($ct)).($final ? "\x01" : "\x00").$iv.$tag.$ct);
            $chunk = $next;
            $index++;
        } while (! $final);
        fclose($src);
        fclose($dst);
    }

    public function decryptFile(string $in, string $out): void
    {
        $src = fopen($in, 'rb');
        if (fread($src, strlen(self::MAGIC)) !== self::MAGIC) {
            throw new RuntimeException('Not a backup file.');
        }
        $dst = fopen($out, 'wb');
        $index = 0;
        $sawFinal = false;
        while (($header = fread($src, 33)) !== '' && $header !== false) {
            if (strlen($header) !== 33 || $sawFinal) {
                throw new RuntimeException('Backup file is corrupted.');
            }
            $len = unpack('N', substr($header, 0, 4))[1];
            $final = $header[4] === "\x01";
            $iv = substr($header, 5, 12);
            $tag = substr($header, 17, 16);
            $ct = $len > 0 ? fread($src, $len) : '';
            $pt = openssl_decrypt((string) $ct, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, $this->aad($index, $final));
            if ($pt === false) {
                throw new RuntimeException('Backup file is corrupted or the key is wrong.');
            }
            fwrite($dst, $pt);
            $sawFinal = $final;
            $index++;
        }
        fclose($src);
        fclose($dst);
        if (! $sawFinal) {
            throw new RuntimeException('Backup file is truncated.');
        }
    }

    private function aad(int $index, bool $final): string
    {
        return self::MAGIC.pack('J', $index).($final ? 'F' : 'C');
    }
}
