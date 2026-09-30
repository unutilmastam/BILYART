<?php

namespace App\Domain\Photos\Services;

use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;

/**
 * spec §58: validate by *content* (not name/extension/client MIME), check size
 * and dimensions, then re-encode to a fresh JPEG with GD. Re-encoding drops
 * EXIF/GPS and any appended/embedded payload (polyglots cannot survive).
 */
final class ImageSanitizer
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    public const MIN_SIDE = 320;

    public const MAX_SIDE = 2560;

    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];

    /** @return array{bytes: string, width: int, height: int, mime: string} */
    public function sanitize(string $raw): array
    {
        if ($raw === '' || strlen($raw) > self::MAX_BYTES) {
            throw ApiException::of(ErrorCode::PHOTO_INVALID);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($raw);
        if (! in_array($mime, self::ALLOWED_MIME, true)) {
            throw ApiException::of(ErrorCode::PHOTO_INVALID);
        }
        $info = @getimagesizefromstring($raw);
        if ($info === false || $info['mime'] !== $mime) {
            throw ApiException::of(ErrorCode::PHOTO_INVALID);
        }
        [$w, $h] = $info;
        if (min($w, $h) < self::MIN_SIDE || max($w, $h) > self::MAX_SIDE) {
            throw ApiException::of(ErrorCode::PHOTO_INVALID);
        }

        $image = @imagecreatefromstring($raw);
        if ($image === false) {
            throw ApiException::of(ErrorCode::PHOTO_INVALID);
        }
        ob_start();
        imagejpeg($image, null, 85);
        $bytes = (string) ob_get_clean();
        unset($image); // GdImage is freed with the object

        return ['bytes' => $bytes, 'width' => $w, 'height' => $h, 'mime' => 'image/jpeg'];
    }
}
