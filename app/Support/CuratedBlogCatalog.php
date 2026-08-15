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
        return array_map(
            static fn (string $class): string => $class::SLUG,
            self::postClasses()
        );
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
            if ($class::SLUG !== $slug || ! method_exists($class, 'faqItems')) {
                continue;
            }

            $items = $class::faqItems();

            return is_array($items) ? $items : [];
        }

        return [];
    }
}
