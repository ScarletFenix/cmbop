<?php

namespace App\Support;

use App\Models\Blog;

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
}
