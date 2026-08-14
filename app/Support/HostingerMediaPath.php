<?php

namespace App\Support;

/**
 * Durable public-media folder outside public_html so a Hostinger code
 * deploy cannot wipe catalog images.
 */
final class HostingerMediaPath
{
    /**
     * @return non-empty-string|null
     */
    public static function suggest(?string $basePath = null): ?string
    {
        $base = str_replace('\\', '/', $basePath ?? base_path());

        if (preg_match('#^(/home/[^/]+)#', $base, $match) === 1) {
            return $match[1].'/persistent/media';
        }

        if (preg_match('#^(.*?)/public_html(?:/|$)#', $base, $match) === 1 && $match[1] !== '') {
            return $match[1].'/persistent/media';
        }

        return null;
    }

    /**
     * Hostinger docroot lives under /home/USER/.../public_html. Local
     * /home/ubuntu checkouts and /workspace do not, so they stay untouched.
     */
    public static function looksLikeHostinger(?string $basePath = null): bool
    {
        $base = str_replace('\\', '/', $basePath ?? base_path());

        return str_contains($base, '/public_html')
            && preg_match('#^/home/[^/]+/#', $base) === 1;
    }

    /**
     * Create (if needed) and return a writable media root.
     *
     * @return non-empty-string|null
     */
    public static function ensure(?string $preferred = null): ?string
    {
        $configured = self::configuredPath();
        $configuredOk = $configured !== null && self::makeWritable($configured);
        $underPublicHtml = $configuredOk && str_contains(str_replace('\\', '/', $configured), 'public_html');

        if ($configuredOk && ! $underPublicHtml) {
            return $configured;
        }

        $suggested = $preferred ?? self::suggest();
        if (is_string($suggested) && $suggested !== '' && self::makeWritable($suggested)) {
            return $suggested;
        }

        return $configuredOk ? $configured : null;
    }

    /**
     * @return non-empty-string|null
     */
    private static function configuredPath(): ?string
    {
        $configured = config('filesystems.media_path');
        if (! is_string($configured)) {
            return null;
        }

        $path = rtrim($configured, DIRECTORY_SEPARATOR);

        return $path !== '' ? $path : null;
    }

    public static function applyRuntime(string $path): void
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        config([
            'filesystems.media_path' => $path,
            'filesystems.disks.public.root' => $path,
            'filesystems.links.'.public_path('storage') => $path,
        ]);
    }

    private static function makeWritable(string $path): bool
    {
        if (is_dir($path)) {
            return is_writable($path);
        }

        try {
            if (! mkdir($path, 0755, true) && ! is_dir($path)) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        return is_dir($path) && is_writable($path);
    }
}
