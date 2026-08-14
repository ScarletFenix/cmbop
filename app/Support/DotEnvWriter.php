<?php

namespace App\Support;

/**
 * Best-effort .env key update. Used by Hostinger self-heal so MEDIA_PATH and
 * APP_URL survive the next php-fpm worker without a manual edit.
 */
final class DotEnvWriter
{
    public static function set(string $key, string $value, ?string $path = null): bool
    {
        if (! preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
            return false;
        }

        $path ??= base_path('.env');
        if (! is_file($path) || ! is_writable($path)) {
            return false;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return false;
        }

        $line = $key.'='.self::encode($value);
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
        if (preg_match($pattern, $contents)) {
            $contents = preg_replace($pattern, $line, $contents, 1) ?? $contents;
        } else {
            $contents = rtrim($contents)."\n".$line."\n";
        }

        return file_put_contents($path, $contents) !== false;
    }

    private static function encode(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_.:\\/@+-]+$/', $value)) {
            return $value;
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
