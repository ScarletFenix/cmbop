<?php

namespace Tests\Unit;

use App\Support\CuratedBlogCatalog;
use App\Support\HowToPriceYourSiteBlogPost;
use PHPUnit\Framework\TestCase;

class CuratedBlogCatalogTest extends TestCase
{
    public function test_unknown_slug_has_no_faq(): void
    {
        $this->assertSame([], CuratedBlogCatalog::faqForSlug('not-a-real-post'));
        $this->assertSame([], CuratedBlogCatalog::faqForSlug(null));
    }

    public function test_publisher_supply_slug_has_faq_items(): void
    {
        $items = CuratedBlogCatalog::faqForSlug(HowToPriceYourSiteBlogPost::SLUG);

        $this->assertNotEmpty($items);
        $this->assertArrayHasKey('question', $items[0]);
        $this->assertArrayHasKey('answer', $items[0]);
    }

    public function test_catalog_includes_publisher_supply_slugs(): void
    {
        $this->assertContains(HowToPriceYourSiteBlogPost::SLUG, CuratedBlogCatalog::slugs());
    }
}
