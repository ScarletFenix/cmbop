<?php

namespace App\Support;

/**
 * Shared limits / helpers for admin & marketing site cover uploads.
 * App cap is 10 MB; effective max also respects PHP upload_max_filesize / post_max_size.
 */
final class SiteImageUpload
{
    public const APP_MAX_KILOBYTES = 10240;

    /**
     * Paths already persisted by the dedicated upload-image endpoint.
     */
    public const STORED_PATH_REGEX = '/^sites\/[A-Za-z0-9._-]+(?:\/[A-Za-z0-9._-]+)*\.(jpe?g|png|gif|webp)$/i';

    public static function maxKilobytes(): int
    {
        return max(1, min(self::APP_MAX_KILOBYTES, self::phpUploadMaxKilobytes()));
    }

    public static function maxMegabytesLabel(): int
    {
        return max(1, (int) floor(self::maxKilobytes() / 1024));
    }

    /**
     * Accept a new upload, or a stored public path from upload-image.
     *
     * @return array<int, string>|string
     */
    public static function fieldRules(bool $hasUploadedFile, bool $required = false): array|string
    {
        $presence = $required ? 'required' : 'nullable';

        if ($hasUploadedFile) {
            return $presence.'|file|mimes:jpeg,png,jpg,gif,webp|max:'.self::maxKilobytes();
        }

        return [$presence, 'string', 'max:255', 'regex:'.self::STORED_PATH_REGEX];
    }

    public static function normalizeStoredPath(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $path = ltrim(str_replace('\\', '/', $value), '/');
        if ($path === '' || str_contains($path, '..') || preg_match(self::STORED_PATH_REGEX, $path) !== 1) {
            return null;
        }

        return $path;
    }

    /**
     * Lowest of upload_max_filesize and (post_max_size minus a small form-fields headroom).
     */
    public static function phpUploadMaxKilobytes(): int
    {
        return PhpIniSize::uploadMaxKilobytes(self::APP_MAX_KILOBYTES);
    }
}
