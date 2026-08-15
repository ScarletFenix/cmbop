<?php

namespace App\Support;

use App\Models\Blog;
use Illuminate\Support\Facades\Schema;

/**
 * Single registry of code-defined pillar posts (slugs + FAQ payloads).
 */
class CuratedBlogCatalog
{
    /**
     * @return list<class-string>
     */
    public static function postClasses(): array
    {
        return [
            BacklinksAufbauenBlogPost::class,
            GastbeitraegeEuropaBlogPost::class,
            DofollowNofollowAnkertexteBlogPost::class,
            LiveLinkChecklistBlogPost::class,
            AdvertiserPlatformGuideBlogPost::class,
            PublisherPlatformGuideBlogPost::class,
            ChoosePublisherSiteBlogPost::class,
            WalletEscrowRefundsBlogPost::class,
            LiveLinkRemovedBlogPost::class,
            GuestPostBriefBlogPost::class,
            MarketplaceVsOutreachBlogPost::class,
            AiAeoGuestPostsBlogPost::class,
            GuestPostsEuropeEnBlogPost::class,
            DofollowNofollowAnchorsEnBlogPost::class,
            AdvertiserGuideDeBlogPost::class,
            PublisherGuideDeBlogPost::class,
            AcheterGuestPostsFrBlogPost::class,
            ChoisirEditeurFrBlogPost::class,
            GastpostsKopenNlBlogPost::class,
            UitgeversKiezenNlBlogPost::class,
            GuestPostsUkUsBlogPost::class,
            HowToPriceYourSiteBlogPost::class,
            WhySitesGetRejectedBlogPost::class,
            FasterPublisherPayoutsBlogPost::class,
        ];
    }

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        $slugs = [];

        foreach (self::postClasses() as $class) {
            try {
                if (! class_exists($class) || ! defined($class.'::SLUG')) {
                    continue;
                }
                $slugs[] = $class::SLUG;
            } catch (\Throwable) {
                continue;
            }
        }

        return $slugs;
    }

    /**
     * @return list<array{question: string, answer: string}>
     */
    public static function faqForSlug(?string $slug): array
    {
        if ($slug === null || $slug === '') {
            return [];
        }

        foreach (self::postClasses() as $class) {
            try {
                if (! class_exists($class) || ! defined($class.'::SLUG') || $class::SLUG !== $slug) {
                    continue;
                }
                if (! method_exists($class, 'faqItems')) {
                    return [];
                }

                $items = $class::faqItems();

                return is_array($items) ? $items : [];
            } catch (\Throwable) {
                continue;
            }
        }

        return [];
    }

    /**
     * Pillar FAQ is keyed by catalog slug. Public pages must not look up
     * a renamed / uniquified translation slug or blogs.slug alone.
     *
     * @return list<array{question: string, answer: string}>
     */
    public static function faqForBlog(Blog $blog, ?string $resolvedSlug = null): array
    {
        if (filled($blog->curated_key)) {
            return self::faqForSlug((string) $blog->curated_key);
        }

        // Legacy unkeyed pillar only. A custom post that reused a catalog
        // slug must not inherit that pillar's FAQ schema.
        if ($blog->manually_edited_at) {
            return [];
        }

        foreach (array_unique(array_filter([$blog->slug, $resolvedSlug])) as $slug) {
            $items = self::faqForSlug(is_string($slug) ? $slug : null);
            if ($items !== []) {
                return $items;
            }
        }

        return [];
    }

    /**
     * Point hardcoded /blog/{catalog-slug} links at the live pillar slug.
     * After uniquify the catalog URL may belong to a custom post.
     */
    public static function rewriteCatalogLinks(?string $html): string
    {
        $html = (string) $html;
        if ($html === '' || ! str_contains($html, '/blog/')) {
            return $html;
        }

        try {
            if (! Schema::hasTable('blogs') || ! Schema::hasColumn('blogs', 'curated_key')) {
                return $html;
            }

            $map = Blog::query()
                ->whereNotNull('curated_key')
                ->where('curated_key', '!=', '')
                ->whereColumn('curated_key', '!=', 'slug')
                ->pluck('slug', 'curated_key')
                ->all();
        } catch (\Throwable) {
            return $html;
        }

        if ($map === []) {
            return $html;
        }

        $from = [];
        foreach (array_keys($map) as $catalogSlug) {
            if (! is_string($catalogSlug) || $catalogSlug === '') {
                continue;
            }
            $from[] = preg_quote($catalogSlug, '~');
        }
        if ($from === []) {
            return $html;
        }

        $locales = implode('|', array_map(
            static fn (string $locale): string => preg_quote($locale, '~'),
            PublicI18n::prefixed()
        ));

        $rewritten = preg_replace_callback(
            '~((?:https?://[^"\'\s>]+)?)((?:/(?:'.$locales.'))?)(/blog/)('.implode('|', $from).')(?=["\'?#\s>]|$)~i',
            static function (array $matches) use ($map): string {
                $catalog = $matches[4];
                $public = $map[$catalog] ?? $catalog;
                foreach ($map as $fromSlug => $toSlug) {
                    if (strcasecmp((string) $fromSlug, $catalog) === 0) {
                        $public = (string) $toSlug;
                        break;
                    }
                }

                return $matches[1].$matches[2].$matches[3].$public;
            },
            $html
        );

        return is_string($rewritten) ? $rewritten : $html;
    }
}
