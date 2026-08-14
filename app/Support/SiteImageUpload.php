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
        return PhpIniSize::uploadMaxKilobytes(self::APP_MAX_KILOBYTES);
    }
}
