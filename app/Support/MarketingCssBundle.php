<?php

namespace App\Support;

/**
 * Concatenated, compacted public-layout CSS. Source sheets stay in
 * public/assets/css and remain the files tests and staff layouts load.
 */
class MarketingCssBundle
{
    public const RELATIVE_PATH = 'assets/css/marketing-bundle.css';

    /** Cascade order matches the former public layout <link> list. Hover last. */
    public const FILES = [
        'type-system.css',
        'brand-colors.css',
        'spacing-system.css',
        'button-system.css',
        'form-system.css',
        'glass-tip.css',
        'marketing-saas.css',
        'interaction.css',
        'dialog-system.css',
        'slb-live-search.css',
        'hover-system.css',
    ];

    public static function absolutePath(): string
    {
        return public_path(self::RELATIVE_PATH);
    }

    public static function url(): string
    {
        $path = self::absolutePath();
        $version = is_file($path) ? (string) (@filemtime($path) ?: '1') : '1';

        return asset(self::RELATIVE_PATH).'?v='.$version;
    }

    public static function urlIfReady(): ?string
    {
        self::ensure();

        return is_file(self::absolutePath()) ? self::url() : null;
    }

    public static function ensure(): void
    {
        try {
            if (self::isStale()) {
                self::write();
            }
        } catch (\Throwable) {
            // Public layout still has Bootstrap; skip a broken rebuild.
        }
    }

    public static function isStale(): bool
    {
        $bundle = self::absolutePath();
        if (! is_file($bundle)) {
            return true;
        }

        $bundleMtime = (int) (@filemtime($bundle) ?: 0);
        foreach (self::FILES as $file) {
            $source = public_path('assets/css/'.$file);
            if (! is_file($source) || (int) (@filemtime($source) ?: 0) > $bundleMtime) {
                return true;
            }
        }

        return false;
    }

    public static function write(): string
    {
        $css = self::compile();
        $dir = dirname(self::absolutePath());
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return $css;
        }

        try {
            file_put_contents(self::absolutePath(), $css, LOCK_EX);
        } catch (\Throwable) {
            // Hostinger leftovers can leave public/assets unwritable.
        }

        return $css;
    }

    public static function compile(): string
    {
        $chunks = [];
        foreach (self::FILES as $file) {
            $path = public_path('assets/css/'.$file);
            if (! is_file($path)) {
                continue;
            }
            try {
                $raw = (string) file_get_contents($path);
            } catch (\Throwable) {
                continue;
            }
            $chunks[] = self::minify($raw);
        }

        return implode('', $chunks);
    }

    /**
     * Collapse comments and runs of whitespace. Leave operators inside calc()
     * alone by not stripping spaces around + / -.
     */
    public static function minify(string $css): string
    {
        $css = preg_replace('#/\*!.+?\*/#s', '', $css) ?? $css;
        $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;
        $css = preg_replace('/\s+/', ' ', $css) ?? $css;

        return trim($css);
    }
}
