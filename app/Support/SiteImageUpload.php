<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Shared limits / helpers for admin & marketing site cover uploads.
 * App cap is 10 MB. PHP upload_max_filesize is only a shrink hint for the
 * browser — do not advertise it as the product limit (ini_get is often 2M).
 */
final class SiteImageUpload
{
    public const APP_MAX_KILOBYTES = 10240;

    /**
     * Paths already persisted by the dedicated upload-image endpoint.
     */
    public const STORED_PATH_REGEX = '/^sites\/[A-Za-z0-9._-]+(?:\/[A-Za-z0-9._-]+)*\.(jpe?g|png|gif|webp)$/i';

    /**
     * Advertised / validated app cap. Do not clamp to ini_get(): Hostinger and
     * `php artisan serve` often report the 2M PHP default even when .user.ini
     * already allows 64M, which then rejects a normal screenshot as “under 2 MB”.
     */
    public static function maxKilobytes(): int
    {
        return self::APP_MAX_KILOBYTES;
    }

    public static function maxMegabytesLabel(): int
    {
        return max(1, (int) floor(self::maxKilobytes() / 1024));
    }

    /**
     * Keep only a relative public-disk cover under sites/. Arrays become "Array" if cast.
     */
    public static function publicCoverPath(mixed $raw): ?string
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        if (str_contains($raw, '..') || str_contains($raw, ':') || str_contains($raw, "\0")) {
            return null;
        }

        $path = ltrim(str_replace('\\', '/', $raw), '/');
        if (preg_match('#^sites/[A-Za-z0-9._-]+\.(jpe?g|png|gif|webp)$#i', $path) !== 1) {
            return null;
        }

        return $path;
    }

    public static function deletePublicCover(?string $path): void
    {
        self::deletePublicDiskPath(self::publicCoverPath($path), 'Failed to remove site cover');
    }

    /**
     * Staff-assign screenshots are site-screenshots/site-{id}-*.webp.
     * Shared catalog placeholders (home-placeholder.webp) are never matched.
     */
    public static function publicScreenshotPath(mixed $raw, ?int $siteId = null): ?string
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        if (str_contains($raw, '..') || str_contains($raw, ':') || str_contains($raw, "\0")) {
            return null;
        }

        $path = ltrim(str_replace('\\', '/', $raw), '/');
        $idPattern = $siteId !== null && $siteId > 0 ? (string) (int) $siteId : '[0-9]+';
        if (preg_match('#^site-screenshots/site-'.$idPattern.'-[A-Za-z0-9._-]+\.webp$#i', $path) !== 1) {
            return null;
        }

        return $path;
    }

    public static function deletePublicScreenshot(?string $path, ?int $siteId = null): void
    {
        self::deletePublicDiskPath(
            self::publicScreenshotPath($path, $siteId),
            'Failed to remove site screenshot'
        );
    }

    public static function deleteListingPublicMedia(
        ?string $cover,
        ?string $screenshot = null,
        ?string $thumb = null,
        ?int $siteId = null,
    ): void {
        self::deletePublicCover($cover);
        self::deletePublicScreenshot($screenshot, $siteId);
        self::deletePublicScreenshot($thumb, $siteId);
    }

    private static function deletePublicDiskPath(?string $safe, string $warning): void
    {
        if ($safe === null) {
            return;
        }

        try {
            $disk = Storage::disk('public');
            if ($disk->exists($safe)) {
                $disk->delete($safe);
            }
        } catch (Throwable $e) {
            Log::warning($warning, [
                'path' => $safe,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Lowest of upload_max_filesize and (post_max_size minus a small form-fields headroom).
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
