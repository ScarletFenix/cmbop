<?php

namespace App\Support;

/**
 * Shared limits / helpers for admin & marketing site cover uploads.
 * App cap is 10 MB; effective max also respects PHP upload_max_filesize / post_max_size.
 */
final class SiteImageUpload
{
    public const APP_MAX_KILOBYTES = 10240;

    public static function maxKilobytes(): int
    {
        return max(1, min(self::APP_MAX_KILOBYTES, self::phpUploadMaxKilobytes()));
    }

    public static function maxMegabytesLabel(): int
    {
        return max(1, (int) floor(self::maxKilobytes() / 1024));
    }

    /**
     * Lowest of upload_max_filesize and (post_max_size minus a small form-fields headroom).
     */
    public static function phpUploadMaxKilobytes(): int
    {
        $upload = self::iniSizeToKilobytes(ini_get('upload_max_filesize') ?: '');
        $post = self::iniSizeToKilobytes(ini_get('post_max_size') ?: '');

        $limits = [];
        if ($upload !== null) {
            $limits[] = $upload;
        }
        if ($post !== null) {
            // Leave room for CSRF + other fields so post_max_size is not the silent failure mode.
            $limits[] = max(1, $post - 256);
        }

        return $limits !== [] ? min($limits) : self::APP_MAX_KILOBYTES;
    }

    private static function iniSizeToKilobytes(string $value): ?int
    {
        $value = trim($value);
        if ($value === '' || $value === '0') {
            return null;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float) $value;

        $bytes = match ($unit) {
            'g' => (int) round($number * 1024 * 1024 * 1024),
            'm' => (int) round($number * 1024 * 1024),
            'k' => (int) round($number * 1024),
            default => (int) round((float) $value),
        };

        if ($bytes < 1024) {
            return 1;
        }

        return (int) floor($bytes / 1024);
    }
}
