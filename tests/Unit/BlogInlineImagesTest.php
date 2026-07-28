<?php

namespace Tests\Unit;

use App\Support\BlogInlineImages;
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
