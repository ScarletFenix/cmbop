<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\BlogTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoAndSecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_include_seo_meta_and_security_headers(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('<meta name="description"', false);
        $response->assertSee('name="robots" content="index, follow', false);
        $response->assertSee('<link rel="canonical"', false);
        $response->assertSee('og:title', false);
        $response->assertSee('og:image:width', false);
        $response->assertSee('application/ld+json', false);
        $response->assertSee('Guest Post Marketplace for SEO Backlinks', false);

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertNotEmpty($response->headers->get('Content-Security-Policy'));
    }

    public function test_contact_page_has_dedicated_title(): void
    {
        $this->get('/contact')
            ->assertOk()
            ->assertSee('Contact SEOLinkBuildings — Sales and Support', false)
            ->assertSee('support@seolinkbuildings.com', false);
    }

    public function test_sitemap_and_robots_are_available(): void
    {
        Blog::factory()->published()->create([
            'title' => 'Sitemap Post',
            'slug' => 'sitemap-post',
        ]);
        $blog = Blog::where('slug', 'sitemap-post')->firstOrFail();
        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'en',
            'title' => 'Sitemap Post',
            'slug' => 'sitemap-post',
            'excerpt' => 'Excerpt',
            'content' => '<p>Body</p>',
            'is_published' => true,
        ]);

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml')
            ->assertSee('sitemap-en.xml', false)
            ->assertSee('sitemap-de.xml', false);

        $this->get('/sitemap-en.xml')
            ->assertOk()
            ->assertSee('/blog/sitemap-post', false)
            ->assertSee('/contact', false);

        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Sitemap:', false)
            ->assertSee('Disallow: /admin/', false)
            ->assertSee('Disallow: /marketing/', false)
            ->assertSee('Googlebot', false)
            ->assertSee('bingbot', false)
            ->assertSee('Slurp', false)
            ->assertSee('GPTBot', false)
            ->assertSee('ChatGPT-User', false)
            ->assertSee('OAI-SearchBot', false)
            ->assertSee('Google-Extended', false)
            ->assertSee('PerplexityBot', false)
            ->assertSee('Bytespider', false)
            ->assertSee('Applebot-Extended', false)
            ->assertSee('LinkedInBot', false)
            ->assertSee('llms.txt', false);

        $this->get('/llms.txt')
            ->assertOk()
            ->assertSee('SEOLinkBuildings', false)
            ->assertSee('seolinkbuildings.com', false)
            ->assertSee('Topurlz', false)
            ->assertSee('/pricing', false)
            ->assertSee('16607074', false);
    }

    public function test_auth_pages_use_branded_meta(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Sign In | SEOLinkBuildings', false)
            ->assertSee('name="robots" content="index, follow', false);

        $this->get('/register')
            ->assertOk()
            ->assertSee('€20 Welcome Credit', false)
            ->assertDontSee('meta_register_title');
    }

    public function test_home_includes_website_and_organization_schema(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('"@type":"WebSite"', false)
            ->assertSee('"@type":"Organization"', false)
            ->assertSee('https://www.facebook.com/seolinkbuildings/', false)
            ->assertSee('https://www.instagram.com/seolinkbuildings', false)
            ->assertSee('https://x.com/seolinbuildings', false)
            ->assertSee('https://www.youtube.com/@seolinkbuildingss', false);
    }

    public function test_faq_page_includes_faqpage_schema(): void
    {
        $this->get('/faq')
            ->assertOk()
            ->assertSee('"@type":"FAQPage"', false)
            ->assertSee('"@type":"Question"', false)
            ->assertSee('BreadcrumbList', false);
    }

    public function test_pricing_page_includes_offer_schema(): void
    {
        $this->get('/pricing')
            ->assertOk()
            ->assertSee('"@type":"Service"', false)
            ->assertSee('"@type":"Offer"', false)
            ->assertSee('"price":"499"', false)
            ->assertSee('EUR', false);
    }

    public function test_about_page_includes_company_entity(): void
    {
        $this->get('/about')
            ->assertOk()
            ->assertSee('AboutPage', false)
            ->assertSee('16607074', false)
            ->assertSee('Wenlock', false)
            ->assertSee('BreadcrumbList', false);
    }

    public function test_blog_show_includes_article_structured_data(): void
    {
        $blog = Blog::factory()->published()->create([
            'title' => 'Structured Data Post',
            'slug' => 'structured-data-post',
            'excerpt' => 'A short excerpt for SEO.',
            'featured_image' => 'blogs/featured/structured-data.jpg',
        ]);
        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'en',
            'title' => 'Structured Data Post',
            'slug' => 'structured-data-post',
            'excerpt' => 'A short excerpt for SEO.',
            'content' => '<p>Body</p>',
            'is_published' => true,
        ]);

        $this->get(route('blog.show', ['slug' => $blog->slug]))
            ->assertOk()
            ->assertSee('BlogPosting', false)
            ->assertSee('Structured Data Post', false)
            ->assertSee('twitter:card', false)
            ->assertSee('storage/blogs/featured/structured-data.jpg', false)
            ->assertSee('BreadcrumbList', false);
    }

    public function test_help_widget_has_accessible_labels(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('aria-label="Open help and feedback"', false)
            ->assertSee('role="dialog"', false)
            ->assertSee('role="tablist"', false);
    }

    public function test_csp_allows_quill_and_chart_cdns(): void
    {
        $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('cdn.quilljs.com', $csp);
        $this->assertStringContainsString('cdn.jsdelivr.net', $csp);
        $this->assertStringContainsString('js.stripe.com', $csp);

        $htaccess = (string) file_get_contents(public_path('.htaccess'));
        $this->assertStringNotContainsString(
            'Content-Security-Policy',
            $htaccess,
            'A second CSP in .htaccess is intersected by the browser and blocks Quill/jsDelivr.'
        );
    }
}
