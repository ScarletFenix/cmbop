<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Local width/height for public blog images. Never probes HTTP.
 *
 * Body and featured markup must not guess 1200×675 (or any other ratio):
 * curated HTML is often wrong for the file on disk. Missing local files
 * degrade to HTML integer pairs when both are present, otherwise omit.
 */
final class PublicImageDimensions
{
    private const MAX_EDGE = 8192;

    /** @var array<string, array{width: int, height: int}|null> */
    private static array $cache = [];

    /**
     * @return array{width: int, height: int}|null
     */
    public static function forUrl(string $src): ?array
    {
        $src = trim($src);
        if ($src === '' || str_starts_with(strtolower($src), 'data:')) {
            return null;
        }

        $path = $src;
        if (preg_match('#^(https?:)?//#i', $src) === 1) {
            $parsed = parse_url($src, PHP_URL_PATH);
            $path = is_string($parsed) ? $parsed : '';
        }

        $path = rawurldecode((string) $path);
        $path = '/'.ltrim(str_replace('\\', '/', $path), '/');
        if ($path === '/' || str_contains($path, '..') || str_contains($path, "\0")) {
            return null;
        }

        if (preg_match('#^/(?:storage|media)/(blogs/(?:content|featured)/[A-Za-z0-9._-]+\.(jpe?g|png|gif|webp))$#i', $path, $matches) === 1) {
            return self::forPublicDiskPath($matches[1]);
        }

        if (preg_match('#^/assets/img/blog/([A-Za-z0-9._-]+\.(jpe?g|png|gif|webp))$#i', $path, $matches) === 1) {
            return self::forPublicDiskPath('blogs/content/'.$matches[1]);
        }

        return null;
    }

    /**
     * @return array{width: int, height: int}|null
     */
    public static function forPublicDiskPath(string $relative): ?array
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if ($relative === '' || str_contains($relative, '..') || str_contains($relative, "\0")) {
            return null;
        }
        if (preg_match('#^blogs/(?:content|featured)/[A-Za-z0-9._-]+\.(jpe?g|png|gif|webp)$#i', $relative) !== 1) {
            return null;
        }

        $candidates = [];
        try {
            if (class_exists(Storage::class) && function_exists('app') && app()->bound('filesystem')) {
                $candidates[] = Storage::disk('public')->path($relative);
            }
        } catch (Throwable) {
        }

        $filename = basename($relative);
        if (function_exists('public_path')) {
            $candidates[] = public_path('assets/img/blog/'.$filename);
        }

        foreach ($candidates as $file) {
            $dims = self::fromLocalFile(is_string($file) ? $file : '');
            if ($dims !== null) {
                return $dims;
            }
        }

        return null;
    }

    /**
     * @return array{width: int, height: int}|null
     */
    public static function fromLocalFile(string $file): ?array
    {
        if ($file === '' || str_contains($file, "\0") || ! is_file($file)) {
            return null;
        }

        $real = realpath($file);
        if ($real === false || ! is_file($real)) {
            return null;
        }

        if (array_key_exists($real, self::$cache)) {
            return self::$cache[$real];
        }

        $info = @getimagesize($real);
        if (! is_array($info) || ! isset($info[0], $info[1], $info[2])) {
            return self::$cache[$real] = null;
        }

        $width = (int) $info[0];
        $height = (int) $info[1];
        $type = (int) $info[2];
        $allowed = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF];
        if (defined('IMAGETYPE_WEBP')) {
            $allowed[] = IMAGETYPE_WEBP;
        }

        if ($width < 1 || $height < 1 || $width > self::MAX_EDGE || $height > self::MAX_EDGE || ! in_array($type, $allowed, true)) {
            return self::$cache[$real] = null;
        }

        return self::$cache[$real] = ['width' => $width, 'height' => $height];
    }
}
