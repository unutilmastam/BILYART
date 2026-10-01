<?php

namespace App\Domain\Photos\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Stores photos on the private local disk (storage/app/private, never under
 * public/ and never served by URL — `serve` is disabled). Paths are built only
 * by PhotoService from ids, and are re-validated here against traversal.
 */
final class LocalPrivateDisk implements PhotoStorage
{
    // Session photos, and subscription payment receipts (same private disk, same rules).
    private const ALLOWED = '#^tenants/\d+/(sessions/[0-9A-HJKMNP-TV-Z]{26}|receipts)/[0-9A-HJKMNP-TV-Z]{26}\.jpg$#';

    private function disk(): Filesystem
    {
        return Storage::disk('local');
    }

    public function put(string $path, string $bytes): void
    {
        $this->disk()->put($this->safe($path), $bytes, ['visibility' => 'private']);
    }

    public function get(string $path): ?string
    {
        $path = $this->safe($path);

        return $this->disk()->exists($path) ? $this->disk()->get($path) : null;
    }

    public function delete(string $path): void
    {
        $this->disk()->delete($this->safe($path));
    }

    public function exists(string $path): bool
    {
        return $this->disk()->exists($this->safe($path));
    }

    public function probe(): bool
    {
        $file = 'health/'.bin2hex(random_bytes(8)).'.txt';
        try {
            $this->disk()->put($file, 'ok');
            $ok = $this->disk()->get($file) === 'ok';
            $this->disk()->delete($file);

            return $ok;
        } catch (\Throwable) {
            return false;
        }
    }

    private function safe(string $path): string
    {
        if (! preg_match(self::ALLOWED, $path)) {
            throw new InvalidArgumentException('Illegal photo path.');
        }

        return $path;
    }
}
