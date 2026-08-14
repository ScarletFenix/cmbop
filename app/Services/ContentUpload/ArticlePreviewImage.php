<?php

namespace App\Services\ContentUpload;

use App\Services\SiteEnrichment\ImageOptimizationService;

/**
 * Compress extracted/editor images for website preview only.
 * Never used on the original .docx on the private disk.
 */
class ArticlePreviewImage
{
    /** Skip WebP when the source is already this small (bytes). */
    public const SKIP_UNDER_BYTES = 8192;

    public const WEBP_QUALITY = 82;

    /**
     * @return array{0: string, 1: string} binary, extension
     */
    public function compressForPreview(string $binary, string $ext): array
    {
        $ext = strtolower(ltrim($ext, '.'));
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)) {
            $ext = 'png';
        }

        if ($binary === '') {
            return [$binary, $ext];
        }

        if ($ext === 'gif' && self::isAnimatedGif($binary)) {
            return [$binary, $ext];
        }

        if (strlen($binary) <= self::SKIP_UNDER_BYTES) {
            return [$binary, $ext];
        }

        if (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp')) {
            return [$binary, $ext];
        }

        $webp = app(ImageOptimizationService::class)->toWebp($binary, self::WEBP_QUALITY);
        if (! is_string($webp) || $webp === '' || strlen($webp) >= strlen($binary)) {
            return [$binary, $ext];
        }

        return [$webp, 'webp'];
    }

    /**
     * True when the GIF has more than one frame (or a Netscape loop block).
     */
    public static function isAnimatedGif(string $binary): bool
    {
        if (! str_starts_with($binary, 'GIF87a') && ! str_starts_with($binary, 'GIF89a')) {
            return false;
        }

        if (str_contains($binary, 'NETSCAPE2.0')) {
            return true;
        }

        $frames = 0;
        $offset = 0;
        while ($frames < 2) {
            $gce = strpos($binary, "\x00\x21\xF9\x04", $offset);
            if ($gce === false) {
                break;
            }
            $separator = strpos($binary, "\x00\x2C", $gce + 1);
            if ($separator === false) {
                break;
            }
            if ($gce + 8 === $separator) {
                $frames++;
            }
            $offset = $separator + 1;
        }

        return $frames > 1;
    }
}
