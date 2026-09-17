<?php

namespace App\Support;

use App\Models\Blog;
use App\Models\BlogTranslation;
use App\Services\CuratedBlogWriter;
use Illuminate\Support\Facades\Schema;

/**
 * 301 thin BlogSeeder stubs onto the ranking pillars so they stop competing.
 */
class ThinBlogRedirects
{
    /**
     * @return array<string, string> old public slug => catalog slug
     */
    public static function map(): array
    {
        return [
            'how-to-build-high-quality-backlinks-in-2026' => HowToGetBacklinksBlogPost::SLUG,
            'digital-pr-ideas-that-earn-coverage-and-links' => LinkBuildingGuideBlogPost::SLUG,
            'guest-posting-checklist-for-advertisers' => GuestPostingGuideBlogPost::SLUG,
            'choosing-publishers-by-country-and-language' => ChoosePublisherSiteBlogPost::SLUG,
        ];
    }

    public static function targetSlug(string $slug): ?string
    {
        $slug = strtolower(trim($slug));
        if ($slug === '') {
            return null;
        }

        return self::map()[$slug] ?? null;
    }

    public static function redirectUrl(string $slug, ?string $locale = null): ?string
    {
        $catalog = self::targetSlug($slug);
        if ($catalog === null) {
            return null;
        }

        $locale = $locale ?: 'en';

        try {
            if (class_exists(CuratedBlogWriter::class)) {
                $blog = CuratedBlogWriter::findExisting($catalog);
                if ($blog) {
                    return $blog->canonicalUrl($locale, 'en');
                }
            }
        } catch (\Throwable) {
            // Fall through to the catalog path.
        }

        if (class_exists(PublicI18n::class)) {
            return PublicI18n::urlForLocale('blog/'.$catalog, $locale);
        }

        return '/blog/'.$catalog;
    }

    /**
     * Hide leftover BlogSeeder rows from the index. URLs still 301.
     */
    public static function unpublishLegacy(): int
    {
        try {
            if (! Schema::hasTable('blogs')) {
                return 0;
            }

            $slugs = array_keys(self::map());
            $query = Blog::query()->whereIn('slug', $slugs)->where('status', 'published');
            $ids = $query->pluck('id')->all();
            if ($ids === []) {
                return 0;
            }

            $updated = Blog::query()->whereIn('id', $ids)->update(['status' => 'draft']);

            if (Schema::hasTable('blog_translations')) {
                BlogTranslation::query()
                    ->whereIn('blog_id', $ids)
                    ->update(['is_published' => false]);
            }

            return (int) $updated;
        } catch (\Throwable) {
            return 0;
        }
    }
}
