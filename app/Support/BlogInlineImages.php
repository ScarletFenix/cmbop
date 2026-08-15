<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Publishes curated blog inline images to the public disk (same path Quill uses).
 * Root-relative /storage/... URLs work on production; /assets/img/blog/... often 404s
 * when that subdirectory is missing from the deploy document root.
 */
class BlogInlineImages
{
    public const PUBLIC_DIR = 'assets/img/blog';

    public const STORAGE_DIR = 'blogs/content';

    /**
     * Copy one file from public/assets/img/blog onto the public disk (MEDIA_PATH).
     */
    public static function publish(string $filename): bool
    {
        $filename = basename($filename);
        if ($filename === '' || $filename === '.' || $filename === '..') {
            return false;
        }

        $storagePath = self::STORAGE_DIR.'/'.$filename;
        $source = public_path(self::PUBLIC_DIR.'/'.$filename);

        if (File::isFile($source)) {
            Storage::disk('public')->put($storagePath, File::get($source));

            return true;
        }

        return Storage::disk('public')->exists($storagePath);
    }

    /**
     * Copy a curated featured JPG onto the public disk (not hardcoded storage/app/public).
     * Hostinger MEDIA_PATH is the disk /media streams from; writing only to
     * storage/app/public leaves /media/blogs/featured/... 404.
     */
    public static function publishFeatured(string $storagePath, ?string $publicAsset = null): bool
    {
        $storagePath = ltrim(str_replace('\\', '/', $storagePath), '/');
        if (! str_starts_with($storagePath, 'blogs/featured/') || str_contains($storagePath, '..')) {
            return false;
        }

        $filename = basename($publicAsset ?: $storagePath);
        if ($filename === '' || $filename === '.' || $filename === '..') {
            return false;
        }

        $candidates = array_values(array_unique(array_filter([
            $publicAsset ? public_path($publicAsset) : null,
            public_path(self::PUBLIC_DIR.'/'.$filename),
        ])));

        foreach ($candidates as $source) {
            if (! File::isFile($source)) {
                continue;
            }

            Storage::disk('public')->put($storagePath, File::get($source));

            return true;
        }

        return Storage::disk('public')->exists($storagePath);
    }

    /**
     * Bundled pillar files live in public/assets/img/blog and must not be deleted
     * when an admin removes/replaces an image in one post.
     */
    public static function isBundledAsset(?string $path): bool
    {
        $filename = basename((string) $path);
        if ($filename === '' || $filename === '.' || $filename === '..') {
            return false;
        }

        return File::isFile(public_path(self::PUBLIC_DIR.'/'.$filename));
    }

    /**
     * Root-relative URL served via the public storage symlink (/storage/...).
     */
    public static function publicUrl(string $filename): string
    {
        $filename = basename($filename);
        self::publish($filename);

        return '/storage/'.self::STORAGE_DIR.'/'.$filename;
    }

    /**
     * Publish every file under public/assets/img/blog into blogs/content.
     */
    public static function publishAllFromPublicAssets(): int
    {
        $dir = public_path(self::PUBLIC_DIR);
        if (! File::isDirectory($dir)) {
            Log::warning('Blog inline image source directory missing', ['path' => $dir]);

            return 0;
        }

        $count = 0;
        foreach (File::files($dir) as $file) {
            if (self::publish($file->getFilename())) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Copy catalog featured JPGs onto the public disk.
     * Content heal (publishAllFromPublicAssets) only writes blogs/content/;
     * /media/blogs/featured/... still 404s after a fresh MEDIA_PATH unless this runs.
     */
    public static function publishAllFeaturedFromCatalog(): int
    {
        $count = 0;

        foreach (CuratedBlogCatalog::postClasses() as $class) {
            try {
                if (! class_exists($class) || ! defined($class.'::FEATURED_STORAGE')) {
                    continue;
                }

                $asset = defined($class.'::FEATURED_ASSET') ? $class::FEATURED_ASSET : null;
                if (self::publishFeatured($class::FEATURED_STORAGE, is_string($asset) ? $asset : null)) {
                    $count++;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $count;
    }

    /**
     * Rewrite legacy /assets/img/blog/... (and absolute variants) to /storage/blogs/content/...
     * and ensure the target file is published when the public asset exists.
     */
    public static function rewriteLegacyAssetUrls(string $html): string
    {
        $rewritten = preg_replace_callback(
            '#(?:https?://[^"\']+)?/assets/img/blog/([^"\'?\s>]+)#i',
            static function (array $matches): string {
                return self::publicUrl($matches[1]);
            },
            $html
        );

        return is_string($rewritten) ? $rewritten : $html;
    }
}
