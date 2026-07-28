<?php

namespace App\Services;

use App\Models\Blog;
use App\Support\BacklinksAufbauenBlogPost;
use App\Support\BlogInlineImages;
use App\Support\DofollowNofollowAnkertexteBlogPost;
use App\Support\GastbeitraegeEuropaBlogPost;
use App\Support\LiveLinkChecklistBlogPost;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class CuratedBlogSync
{
    /** @return list<string> */
    public static function curatedSlugs(): array
    {
        return [
            BacklinksAufbauenBlogPost::SLUG,
            GastbeitraegeEuropaBlogPost::SLUG,
            DofollowNofollowAnkertexteBlogPost::SLUG,
            LiveLinkChecklistBlogPost::SLUG,
        ];
    }

    /**
     * Ensure primary_locale exists (older production DBs may have skipped that migration).
     */
    public static function ensureSchema(): void
    {
        if (! Schema::hasTable('blogs')) {
            return;
        }

        if (! Schema::hasColumn('blogs', 'primary_locale')) {
            Schema::table('blogs', function ($table) {
                $table->string('primary_locale', 5)->nullable()->after('slug');
            });
        }
    }

    /**
     * Run all curated upserts. Returns true when Artisan exits 0.
     */
    public static function sync(): bool
    {
        self::ensureSchema();

        try {
            $exit = Artisan::call('blog:upsert-curated');
            $output = trim(Artisan::output());
            if ($exit !== 0) {
                Log::error('Curated blog sync failed', ['exit' => $exit, 'output' => $output]);

                return false;
            }

            Cache::forget('curated_blogs_present_v1');

            return true;
        } catch (\Throwable $e) {
            Log::error('Curated blog sync exception', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Idempotent heal for production: if any curated slug is missing, sync once.
     * Also re-sync when content still points at /assets/img/blog/ (broken on many hosts).
     * Safe to call from public blog routes after deploy.
     */
    public static function ensurePresent(): void
    {
        if (! Schema::hasTable('blogs')) {
            return;
        }

        $present = Cache::remember('curated_blogs_present_v1', now()->addMinutes(30), function () {
            self::ensureSchema();

            $slugs = self::curatedSlugs();
            $found = Blog::query()
                ->whereIn('slug', $slugs)
                ->pluck('slug')
                ->all();

            if (count($found) >= count($slugs)) {
                return true;
            }

            Log::warning('Curated blogs missing from database — auto-syncing', [
                'expected' => $slugs,
                'found' => $found,
            ]);

            return self::sync();
        });

        // If cache says false from a failed sync, retry on next request after short TTL
        if ($present === false) {
            Cache::forget('curated_blogs_present_v1');
        }

        self::ensureInlineImagesOnStorage();
    }

    /**
     * Heal curated posts whose HTML still references public /assets/img/blog/ paths.
     */
    public static function ensureInlineImagesOnStorage(): void
    {
        Cache::remember('curated_blogs_inline_storage_v1', now()->addMinutes(30), function () {
            self::ensureSchema();
            BlogInlineImages::publishAllFromPublicAssets();

            $needsRewrite = Blog::query()
                ->whereIn('slug', self::curatedSlugs())
                ->where('content', 'like', '%/assets/img/blog/%')
                ->exists();

            if (! $needsRewrite) {
                return true;
            }

            Log::warning('Curated blogs still use /assets/img/blog/ inline paths — re-syncing to storage URLs');

            $ok = self::sync();
            if (! $ok) {
                Cache::forget('curated_blogs_inline_storage_v1');
            }

            return $ok;
        });
    }
}
