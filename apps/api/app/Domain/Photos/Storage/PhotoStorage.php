<?php

namespace App\Domain\Photos\Storage;

/** Where customer photos live (spec §22). v1 adapter: LocalPrivateDisk (outside the web root). */
interface PhotoStorage
{
    public function put(string $path, string $bytes): void;

    public function get(string $path): ?string;

    public function delete(string $path): void;

    public function exists(string $path): bool;

    /** Health check: can we write, read back and delete a probe file? */
    public function probe(): bool;
}
