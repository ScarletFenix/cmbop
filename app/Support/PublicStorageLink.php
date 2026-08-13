<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Keep public/storage → public disk root in sync (Hostinger MEDIA_PATH).
 *
 * Hostinger often blocks is_file() through the symlink (open_basedir) even when
 * the web server can serve /storage/... — never treat that alone as failure.
 */
final class PublicStorageLink
{
    /**
     * Ensure public/storage points at the configured public disk root.
     *
     * @return array{ok: bool, repaired: bool, message: ?string}
     */
    public static function ensure(): array
    {
        $target = self::normalizePath((string) config('filesystems.disks.public.root'));
        $link = public_path('storage');

        if ($target === '') {
            return ['ok' => false, 'repaired' => false, 'message' => 'Public disk root is not configured.'];
        }

        if (! is_dir($target)) {
            try {
                if (! mkdir($target, 0755, true) && ! is_dir($target)) {
                    return ['ok' => false, 'repaired' => false, 'message' => 'Public disk root does not exist: '.$target];
                }
            } catch (\Throwable $e) {
                return ['ok' => false, 'repaired' => false, 'message' => 'Cannot create public disk root: '.$e->getMessage()];
            }
        }

        if (is_link($link)) {
            $current = self::normalizePath((string) readlink($link));
            if (self::pathsEqual($current, $target)) {
                return ['ok' => true, 'repaired' => false, 'message' => null];
            }

            if (! @unlink($link)) {
                return [
                    'ok' => false,
                    'repaired' => false,
                    'message' => 'public/storage symlink points elsewhere and could not be replaced. Run: rm -f public/storage && php artisan storage:link',
                ];
            }
        } elseif (file_exists($link)) {
            $resolved = self::normalizePath((string) (realpath($link) ?: $link));
            if (self::pathsEqual($resolved, $target)) {
                return ['ok' => true, 'repaired' => false, 'message' => null];
            }

            // Empty directory leftover from a bad deploy — remove so we can link.
            if (is_dir($link) && self::directoryIsEmpty($link)) {
                if (! @rmdir($link)) {
                    return [
                        'ok' => false,
                        'repaired' => false,
                        'message' => 'Empty public/storage directory could not be removed. Delete it and run php artisan storage:link',
                    ];
                }
            } else {
                return [
                    'ok' => false,
                    'repaired' => false,
                    'message' => 'public/storage exists but is not a symlink to the media disk. On Hostinger run: rm -f public/storage && php artisan storage:link (MEDIA_PATH must match the symlink target).',
                ];
            }
        }

        if (! @symlink($target, $link)) {
            return [
                'ok' => false,
                'repaired' => false,
                'message' => 'Could not create public/storage symlink. On Hostinger run: rm -f public/storage && php artisan storage:link',
            ];
        }

        Log::info('Recreated public/storage symlink', [
            'target' => $target,
            'link' => $link,
        ]);

        return ['ok' => true, 'repaired' => true, 'message' => null];
    }

    /**
     * True when the public disk holds a non-empty file and /storage can serve it
     * (or the symlink is correctly aimed — Hostinger may block is_file probes).
     */
    public static function pathIsPubliclyReachable(string $path): bool
    {
        $normalized = ltrim(str_replace('\\', '/', $path), '/');
        if ($normalized === '') {
            return false;
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($normalized)) {
            return false;
        }

        try {
            if ((int) $disk->size($normalized) <= 0) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        if (app()->runningUnitTests()) {
            return true;
        }

        $ensure = self::ensure();
        $target = self::normalizePath((string) config('filesystems.disks.public.root'));
        $link = public_path('storage');

        // Direct probe (works when open_basedir allows following the link).
        $publicFile = $link.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $normalized);
        if (@is_file($publicFile) && @filesize($publicFile) > 0) {
            return true;
        }

        // Symlink aimed correctly: web server can serve even if PHP is_file is blocked.
        if (is_link($link)) {
            $current = self::normalizePath((string) readlink($link));
            if (self::pathsEqual($current, $target)) {
                return true;
            }
        }

        // public/storage is the disk root itself (rare but valid).
        if (is_dir($link) && ! is_link($link)) {
            $resolved = self::normalizePath((string) (realpath($link) ?: ''));
            if (self::pathsEqual($resolved, $target)) {
                return true;
            }
        }

        if (! $ensure['ok']) {
            Log::warning('Public storage link check failed after upload', [
                'path' => $normalized,
                'ensure' => $ensure,
                'disk_root' => $target,
                'public_storage' => $link,
            ]);
        }

        return false;
    }

    public static function pathsEqual(string $a, string $b): bool
    {
        return self::normalizePath($a) === self::normalizePath($b);
    }

    private static function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '') {
            return '';
        }

        // Resolve .. and . without requiring the path to exist.
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                if ($part === '' && $parts === []) {
                    $parts[] = '';
                }

                continue;
            }
            if ($part === '..') {
                if (count($parts) > 1) {
                    array_pop($parts);
                }

                continue;
            }
            $parts[] = $part;
        }

        $normalized = implode('/', $parts);
        if (str_starts_with($path, '/') && ! str_starts_with($normalized, '/')) {
            $normalized = '/'.$normalized;
        }

        return rtrim($normalized, '/') ?: (str_starts_with($path, '/') ? '/' : '');
    }

    private static function directoryIsEmpty(string $dir): bool
    {
        $handle = @opendir($dir);
        if ($handle === false) {
            return false;
        }

        try {
            while (($entry = readdir($handle)) !== false) {
                if ($entry !== '.' && $entry !== '..') {
                    return false;
                }
            }
        } finally {
            closedir($handle);
        }

        return true;
    }
}
