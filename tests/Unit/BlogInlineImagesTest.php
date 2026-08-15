<?php

namespace Tests\Unit;

use App\Support\BlogInlineImages;
use App\Support\GastbeitraegeEuropaBlogPost;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BlogInlineImagesTest extends TestCase
{
    public function test_public_url_publishes_to_storage_and_returns_storage_path(): void
    {
        $filename = 'gastbeitraege-europa-sprachen.jpg';
        $url = BlogInlineImages::publicUrl($filename);

        $this->assertSame('/storage/blogs/content/'.$filename, $url);
        $this->assertFileExists(storage_path('app/public/blogs/content/'.$filename));
    }

    public function test_publish_featured_writes_to_public_disk(): void
    {
        Storage::fake('public');

        $this->assertTrue(BlogInlineImages::publishFeatured(
            GastbeitraegeEuropaBlogPost::FEATURED_STORAGE,
            GastbeitraegeEuropaBlogPost::FEATURED_ASSET
        ));
        Storage::disk('public')->assertExists(GastbeitraegeEuropaBlogPost::FEATURED_STORAGE);
        $this->assertTrue(BlogInlineImages::isBundledAsset(GastbeitraegeEuropaBlogPost::FEATURED_STORAGE));
        $this->assertFalse(BlogInlineImages::isBundledAsset('blogs/content/unique-upload-xyz.webp'));
    }

    public function test_rewrite_legacy_asset_urls(): void
    {
        $html = '<img src="/assets/img/blog/gastbeitraege-europa-checkliste.jpg" alt="x">';
        $rewritten = BlogInlineImages::rewriteLegacyAssetUrls($html);

        $this->assertSame(
            '<img src="/storage/blogs/content/gastbeitraege-europa-checkliste.jpg" alt="x">',
            $rewritten
        );
        $this->assertFileExists(storage_path('app/public/blogs/content/gastbeitraege-europa-checkliste.jpg'));
    }
}
