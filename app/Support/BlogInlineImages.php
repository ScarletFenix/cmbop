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
     * Copy one file from public/assets/img/blog into storage/app/public/blogs/content.
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
            File::ensureDirectoryExists(storage_path('app/public/'.self::STORAGE_DIR));
            Storage::disk('public')->put($storagePath, File::get($source));

            return true;
        }

        return Storage::disk('public')->exists($storagePath);
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
