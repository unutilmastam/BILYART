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

    /** Payment receipts: phone screenshots are tall and large; they are scaled down to this longest side. */
    public const DOCUMENT_MAX_BYTES = 8 * 1024 * 1024;

    public const DOCUMENT_MAX_INPUT_SIDE = 8000;

    public const DOCUMENT_OUTPUT_SIDE = 2000;

    /** @return array{bytes: string, width: int, height: int, mime: string} */
    public function sanitize(string $raw): array
    {
        return $this->clean($raw, self::MAX_BYTES, self::MIN_SIDE, self::MAX_SIDE, null);
    }

    /** Same content checks and re-encode, for receipt screenshots/photos (downscaled to DOCUMENT_OUTPUT_SIDE). */
    public function sanitizeDocument(string $raw): array
    {
        return $this->clean($raw, self::DOCUMENT_MAX_BYTES, 200, self::DOCUMENT_MAX_INPUT_SIDE, self::DOCUMENT_OUTPUT_SIDE);
    }

    /** @return array{bytes: string, width: int, height: int, mime: string} */
    private function clean(string $raw, int $maxBytes, int $minSide, int $maxSide, ?int $outputSide): array
    {
        if ($raw === '' || strlen($raw) > $maxBytes) {
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
        if (min($w, $h) < $minSide || max($w, $h) > $maxSide) {
            throw ApiException::of(ErrorCode::PHOTO_INVALID);
        }

        $image = @imagecreatefromstring($raw);
        if ($image === false) {
            throw ApiException::of(ErrorCode::PHOTO_INVALID);
        }
        if ($outputSide !== null && max($w, $h) > $outputSide) {
            $scale = $outputSide / max($w, $h);
            $scaled = imagescale($image, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
            if ($scaled === false) {
                throw ApiException::of(ErrorCode::PHOTO_INVALID);
            }
            $image = $scaled;
            [$w, $h] = [imagesx($image), imagesy($image)];
        }
        ob_start();
        imagejpeg($image, null, 85);
        $bytes = (string) ob_get_clean();
        unset($image); // GdImage is freed with the object

        return ['bytes' => $bytes, 'width' => $w, 'height' => $h, 'mime' => 'image/jpeg'];
    }
}
