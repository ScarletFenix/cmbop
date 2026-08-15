<?php

namespace App\Support;

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
}
