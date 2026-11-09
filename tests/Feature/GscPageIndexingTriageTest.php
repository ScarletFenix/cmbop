<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\BlogTranslation;
use App\Support\LinkBuildingGuideBlogPost;
use App\Support\LocalizedPublicPath;
use App\Support\PublicI18n;
use App\Support\RobotsTxt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GscPageIndexingTriageTest extends TestCase
{
    use RefreshDatabase;

    public function test_terms_and_services_aliases_301_to_canonical_terms(): void
    {
        $this->get('/terms-and-services')
            ->assertRedirect('/terms-of-services');
        $this->assertSame(301, $this->get('/terms-and-services')->status());

        $this->get('/de/terms-and-services')
            ->assertRedirect(LocalizedPublicPath::publicPath('terms-of-services', 'de'));
        $this->assertSame(301, $this->get('/de/terms-and-services')->status());

        $this->get('/de/terms-and-services?ref=gsc')
            ->assertRedirect(LocalizedPublicPath::publicPath('terms-of-services', 'de').'?ref=gsc');

        $this->get('/fr/terms-and-services')
            ->assertRedirect(LocalizedPublicPath::publicPath('terms-of-services', 'fr'));
        $this->get('/nl/terms-and-services')
            ->assertRedirect(LocalizedPublicPath::publicPath('terms-of-services', 'nl'));
    }

    public function test_blog_page_one_query_301s_to_clean_url_and_preserves_other_params(): void
    {
        $this->seedBlogIndex(13);

        $this->get('/blog?page=1')->assertRedirect(url('/blog'));
        $this->assertSame(301, $this->get('/blog?page=1')->status());

        $this->get('/blog?page=1&utm_source=gsc')
            ->assertRedirect(url('/blog').'?utm_source=gsc');

        $this->get('/fr/blog?page=1')->assertRedirect(url('/fr/blog'));
        $this->assertSame(301, $this->get('/fr/blog?page=1')->status());

        $this->get('/nl/blog?page=1')->assertRedirect(url('/nl/blog'));
        $this->get('/blog?page=2')->assertOk();
    }

    public function test_blog_page_two_prev_and_pager_omit_page_one_query(): void
    {
        $this->seedBlogIndex(13);

        $html = $this->get('/blog?page=2')->assertOk()->getContent();
        $this->assertStringContainsString('rel="canonical" href="'.url('/blog').'?page=2"', $html);
        $this->assertStringContainsString('rel="prev" href="'.url('/blog').'"', $html);
        $this->assertStringNotContainsString('rel="prev" href="'.url('/blog').'?page=1"', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/class="page-link"[^>]+href="[^"]*(?:\?|&amp;)page=1(?:&|"|#)/',
            $html
        );

        $fr = $this->get('/fr/blog?page=2')->assertOk()->getContent();
        $this->assertStringContainsString('rel="prev" href="'.url('/fr/blog').'"', $fr);
        $this->assertStringNotContainsString('rel="prev" href="'.url('/fr/blog').'?page=1"', $fr);
    }

    public function test_robots_disallows_lottie_vendor_not_bootstrap_or_login(): void
    {
        $txt = $this->get('/robots.txt')->assertOk()->getContent();
        $this->assertStringContainsString("Disallow: /assets/vendor/lottie/\n", $txt);
        $this->assertStringContainsString("Disallow: /assets/vendor/lottie-web/\n", $txt);
        $this->assertStringNotContainsString("Disallow: /assets/vendor/\n", $txt);
        $this->assertStringNotContainsString('Disallow: /assets/vendor/bootstrap', $txt);
        $this->assertStringNotContainsString("Disallow: /login\n", $txt);
        $this->assertSame(
            RobotsTxt::render('https://seolinkbuildings.com'),
            (string) file_get_contents(public_path('robots.txt'))
        );

        $this->get('/login')
            ->assertOk()
            ->assertSee('name="robots" content="noindex, nofollow', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_truncated_link_building_guide_slug_301s(): void
    {
        $this->get('/blog/link-building-gu')
            ->assertStatus(301)
            ->assertRedirect(PublicI18n::urlForLocale('blog/'.LinkBuildingGuideBlogPost::SLUG, 'en'));
    }

    private function seedBlogIndex(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $blog = Blog::factory()->published()->create([
                'title' => 'Triage Post '.$i,
                'slug' => 'triage-post-'.$i,
            ]);
            BlogTranslation::create([
                'blog_id' => $blog->id,
                'locale' => 'en',
                'title' => 'Triage Post '.$i,
                'slug' => 'triage-post-'.$i,
                'excerpt' => 'Excerpt',
                'content' => '<p>Body</p>',
                'is_published' => true,
            ]);
        }
    }
}
