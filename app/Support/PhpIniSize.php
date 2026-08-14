<?php

namespace App\Support;

/**
 * Parse PHP ini size directives (upload_max_filesize / post_max_size).
 */
final class PhpIniSize
{
    /**
     * Lowest of upload_max_filesize and (post_max_size minus form-field headroom).
     */
    public static function uploadMaxKilobytes(int $fallbackKilobytes = 10240): int
    {
        $upload = self::toKilobytes(ini_get('upload_max_filesize') ?: '');
        $post = self::toKilobytes(ini_get('post_max_size') ?: '');

        $limits = [];
        if ($upload !== null) {
            $limits[] = $upload;
        }
        if ($post !== null) {
            // Leave room for CSRF + other fields so post_max_size is not the silent failure mode.
            $limits[] = max(1, $post - 256);
        }

        return $limits !== [] ? min($limits) : max(1, $fallbackKilobytes);
    }

    public static function toKilobytes(string $value): ?int
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

    public static function megabytesLabel(int $kilobytes): int
    {
        return max(1, (int) round($kilobytes / 1024));
    }
}
