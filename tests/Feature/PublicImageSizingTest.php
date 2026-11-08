<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Services\BlogHtmlSanitizer;
use App\Support\LinkBuildingGuideBlogPost;
use App\Support\PublicI18n;
use Database\Seeders\LinkBuildingGuidesBlogsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicImageSizingTest extends TestCase
{
    use RefreshDatabase;

    public function test_sanitizer_replaces_wrong_html_dims_with_local_getimagesize(): void
    {
        $source = public_path('assets/img/blog/link-building-guide-roadmap.jpg');
        $this->assertFileExists($source);
        $info = getimagesize($source);
        $this->assertIsArray($info);
        Storage::disk('public')->put(
            'blogs/content/link-building-guide-roadmap.jpg',
            (string) file_get_contents($source)
        );

        $clean = (new BlogHtmlSanitizer)->sanitize(
            '<img src="/storage/blogs/content/link-building-guide-roadmap.jpg" alt="Roadmap" width="1200" height="675" loading="lazy">'
        );

        $this->assertStringContainsString('src="/media/blogs/content/link-building-guide-roadmap.jpg"', $clean);
        $this->assertStringContainsString('alt="Roadmap"', $clean);
        $this->assertStringContainsString('width="'.$info[0].'"', $clean);
        $this->assertStringContainsString('height="'.$info[1].'"', $clean);
        $this->assertStringContainsString('loading="lazy"', $clean);
        $this->assertStringContainsString('decoding="async"', $clean);
        $this->assertStringNotContainsString('width="1200"', $clean);
        $this->assertStringNotContainsString('height="675"', $clean);
        $this->assertStringNotContainsString('fetchpriority', $clean);
    }

    public function test_blog_post_featured_is_lcp_with_measured_ratio_and_body_is_lazy(): void
    {
        $this->seed(LinkBuildingGuidesBlogsSeeder::class);

        $featuredPath = storage_path('app/public/'.LinkBuildingGuideBlogPost::FEATURED_STORAGE);
        $this->assertFileExists($featuredPath);
        $featured = getimagesize($featuredPath);
        $this->assertIsArray($featured);

        $roadmapDisk = storage_path('app/public/blogs/content/'.LinkBuildingGuideBlogPost::IMAGE_ROADMAP);
        $roadmapFile = is_file($roadmapDisk)
            ? $roadmapDisk
            : public_path('assets/img/blog/'.LinkBuildingGuideBlogPost::IMAGE_ROADMAP);
        $this->assertFileExists($roadmapFile);
        $roadmap = getimagesize($roadmapFile);
        $this->assertIsArray($roadmap);

        $html = $this->get('/blog/link-building-guide')->assertOk()->getContent();
        $featuredTag = $this->imgTagContaining($html, 'link-building-guide-featured.jpg');
        $bodyTag = $this->imgTagContaining($html, 'link-building-guide-roadmap.jpg');

        $this->assertStringContainsString('width="'.$featured[0].'"', $featuredTag);
        $this->assertStringContainsString('height="'.$featured[1].'"', $featuredTag);
        $this->assertStringContainsString('fetchpriority="high"', $featuredTag);
        $this->assertStringContainsString('decoding="async"', $featuredTag);
        $this->assertStringNotContainsString('loading="lazy"', $featuredTag);
        $this->assertGreaterThan(1, (int) $featured[1]);

        $this->assertStringContainsString('width="'.$roadmap[0].'"', $bodyTag);
        $this->assertStringContainsString('height="'.$roadmap[1].'"', $bodyTag);
        $this->assertStringContainsString('loading="lazy"', $bodyTag);
        $this->assertStringContainsString('decoding="async"', $bodyTag);
        $this->assertStringNotContainsString('fetchpriority', $bodyTag);
        $this->assertStringNotContainsString('width="1200"', $bodyTag);
        $this->assertStringNotContainsString('height="675"', $bodyTag);

        $this->assertMatchesRegularExpression(
            '/\.blog-content img\s*\{[^}]*max-width:\s*100%[^}]*height:\s*auto/s',
            $html
        );

        $this->assertStringContainsString('og:image:width" content="'.$featured[0].'"', $html);
        $this->assertStringContainsString('og:image:height" content="'.$featured[1].'"', $html);
        $this->assertStringContainsString('rel="canonical" href="'.PublicI18n::urlForLocale('blog/link-building-guide', 'en').'"', $html);
        $this->assertStringContainsString('hreflang="en-GB"', $html);
        $this->assertStringContainsString('hreflang="x-default"', $html);
        $this->assertStringNotContainsString('name="robots" content="noindex', $html);
        $this->assertStringContainsString('href="/de/gastbeitrag-kaufen"', $html);
        $this->assertStringContainsString('href="/fr/acheter-backlinks"', $html);
        $this->assertStringContainsString('href="/dk/koeb-backlinks"', $html);
        $this->assertSame(1, preg_match_all('/<h1\b/i', $html));
    }

    public function test_locale_guide_keeps_measured_dims_and_1338_copy(): void
    {
        $this->seed(LinkBuildingGuidesBlogsSeeder::class);
        $featuredPath = storage_path('app/public/'.LinkBuildingGuideBlogPost::FEATURED_STORAGE);
        $featured = getimagesize($featuredPath);
        $this->assertIsArray($featured);

        $html = $this->get('/de/blog/linkaufbau-strategien')->assertOk()->getContent();
        $featuredTag = $this->imgTagContaining($html, 'link-building-guide-featured.jpg');

        $this->assertStringContainsString('width="'.$featured[0].'"', $featuredTag);
        $this->assertStringContainsString('height="'.$featured[1].'"', $featuredTag);
        $this->assertStringContainsString('fetchpriority="high"', $featuredTag);
        $this->assertStringContainsString('Gastbeitrag kaufen oder selbst Outreach?', $html);
        $this->assertStringContainsString('href="/de/gastbeitrag-kaufen"', $html);
        $this->assertStringContainsString('rel="canonical" href="'.PublicI18n::urlForLocale('blog/linkaufbau-strategien', 'de').'"', $html);
        $this->assertStringContainsString('hreflang="de"', $html);
        $this->assertStringNotContainsString('name="robots" content="noindex', $html);
        $this->assertStringNotContainsString('/uk', $html);
        $this->assertSame(1, preg_match_all('/<h1\b/i', $html));
    }

    public function test_blog_index_eagers_first_card_and_lazies_the_rest(): void
    {
        $this->seed(LinkBuildingGuidesBlogsSeeder::class);
        $this->assertGreaterThanOrEqual(2, Blog::query()->published()->count());

        $html = $this->get('/blog')->assertOk()->getContent();
        $this->assertStringContainsString('rel="canonical"', $html);
        $this->assertStringNotContainsString('name="robots" content="noindex', $html);

        preg_match_all('#<img\b[^>]*class="img-fluid w-100 h-100"[^>]*>#i', $html, $imgs);
        $this->assertGreaterThanOrEqual(2, count($imgs[0]));

        $first = $imgs[0][0];
        $this->assertStringContainsString('loading="eager"', $first);
        $this->assertStringContainsString('fetchpriority="high"', $first);
        $this->assertStringContainsString('decoding="async"', $first);
        $this->assertStringContainsString('width="', $first);
        $this->assertStringContainsString('height="', $first);
        $this->assertStringNotContainsString('loading="lazy"', $first);

        $later = $imgs[0][1];
        $this->assertStringContainsString('loading="lazy"', $later);
        $this->assertStringNotContainsString('fetchpriority="high"', $later);
        $this->assertStringContainsString('width="', $later);
        $this->assertStringContainsString('height="', $later);
    }

    public function test_homepage_and_private_surfaces_keep_prior_image_and_robots_policy(): void
    {
        $home = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('width="1006"', $home);
        $this->assertStringContainsString('height="280"', $home);
        $this->assertStringContainsString('fetchpriority="high"', $home);
        $this->assertStringContainsString('og:image:width" content="1200"', $home);
        $this->assertStringContainsString('og:image:height" content="630"', $home);
        $this->assertStringNotContainsString('name="robots" content="noindex', $home);

        $deHome = $this->get('/de')->assertOk()->getContent();
        $this->assertStringContainsString('width="1006"', $deHome);
        $this->assertStringContainsString('height="280"', $deHome);
        $this->assertStringNotContainsString('name="robots" content="noindex', $deHome);

        $this->get('/login')
            ->assertOk()
            ->assertSee('noindex', false);
    }

    private function imgTagContaining(string $html, string $needle): string
    {
        preg_match_all('#<img\b[^>]*>#i', $html, $matches);
        foreach ($matches[0] as $tag) {
            if (str_contains($tag, $needle)) {
                return $tag;
            }
        }

        $this->fail('No <img> contained '.$needle);
    }
}
