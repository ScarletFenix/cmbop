<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\User;
use App\Services\CuratedBlogSync;
use App\Support\AdvertiserPlatformGuideBlogPost;
use App\Support\BacklinksAufbauenBlogPost;
use App\Support\FasterPublisherPayoutsBlogPost;
use App\Support\GastbeitraegeEuropaBlogPost;
use App\Support\HowToPriceYourSiteBlogPost;
use App\Support\LiveLinkChecklistBlogPost;
use App\Support\PublicI18n;
use App\Support\PublisherPlatformGuideBlogPost;
use App\Support\WhySitesGetRejectedBlogPost;
use Database\Seeders\AdvertiserPlatformGuideBlogSeeder;
use Database\Seeders\BacklinksAufbauenBlogSeeder;
use Database\Seeders\GastbeitraegeEuropaBlogSeeder;
use Database\Seeders\LiveLinkChecklistBlogSeeder;
use Database\Seeders\PublisherPlatformGuideBlogSeeder;
use Database\Seeders\PublisherSupplyBlogsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BlogRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_blog_index_loads_via_controller_route(): void
    {
        $this->get(route('blog.index'))
            ->assertOk()
            ->assertViewIs('pages.blog');
    }

    public function test_footer_shows_recent_blog_updates(): void
    {
        $author = User::factory()->create();

        // Warm curated posts first, then publish this update as the newest
        // visible post (future published_at is hidden by the published scope).
        $this->artisan('blog:upsert-curated')->assertSuccessful();
        Blog::query()
            ->whereIn('slug', CuratedBlogSync::curatedSlugs())
            ->update(['published_at' => now()->subDay()]);

        Blog::create([
            'title' => 'Footer Update Post',
            'slug' => 'footer-update-post',
            'excerpt' => 'Daily SEO update for the footer.',
            'content' => '<p>Footer recent updates content.</p>',
            'author' => $author->name,
            'status' => 'published',
            'published_at' => now(),
            'created_by' => $author->id,
        ]);

        $this->get(route('blog.index'))
            ->assertOk()
            ->assertSee('Latest Updates', false)
            ->assertSee('Footer Update Post', false)
            ->assertSee('View all posts', false);
    }

    public function test_future_published_at_posts_are_hidden_from_public_blog(): void
    {
        $author = User::factory()->create();

        Blog::create([
            'title' => 'Scheduled Post',
            'slug' => 'scheduled-post',
            'content' => '<p>Not live yet.</p>',
            'author' => $author->name,
            'status' => 'published',
            'published_at' => now()->addDay(),
            'created_by' => $author->id,
        ]);

        $this->get(route('blog.index'))->assertOk()->assertDontSee('Scheduled Post', false);
        $this->get(route('blog.show', ['slug' => 'scheduled-post']))->assertNotFound();
    }

    public function test_blog_show_displays_published_post(): void
    {
        $author = User::factory()->create();

        $blog = Blog::create([
            'title' => 'Test Post',
            'slug' => 'test-post',
            'content' => 'Full content here',
            'author' => $author->name,
            'status' => 'published',
            'published_at' => now(),
            'created_by' => $author->id,
        ]);

        $this->get(route('blog.show', ['slug' => $blog->slug]))
            ->assertOk()
            ->assertViewIs('pages.blog-single')
            ->assertSee('Test Post')
            ->assertSee('fab fa-facebook-f', false)
            ->assertSee('fab fa-x-twitter', false)
            ->assertSee('fab fa-linkedin-in', false);
    }

    public function test_blog_show_returns_404_for_draft_post(): void
    {
        $author = User::factory()->create();

        Blog::create([
            'title' => 'Draft Post',
            'slug' => 'draft-post',
            'content' => 'Full content here',
            'author' => $author->name,
            'status' => 'draft',
            'published_at' => null,
            'created_by' => $author->id,
        ]);

        $this->get(route('blog.show', ['slug' => 'draft-post']))->assertNotFound();
    }

    public function test_blog_show_returns_404_for_unknown_slug(): void
    {
        $this->get(route('blog.show', ['slug' => 'missing-'.Str::random(8)]))->assertNotFound();
    }

    public function test_german_primary_locale_sets_canonical_and_faq_schema_on_all_locale_urls(): void
    {
        $this->seed(BacklinksAufbauenBlogSeeder::class);

        $slug = BacklinksAufbauenBlogPost::SLUG;
        $canonical = PublicI18n::urlForLocale('blog/'.$slug, 'de');

        $paths = ['/blog/'.$slug];
        foreach (PublicI18n::prefixed() as $locale) {
            $paths[] = '/'.$locale.'/blog/'.$slug;
        }

        foreach ($paths as $path) {
            $this->get($path)
                ->assertOk()
                ->assertSee('Backlinks aufbauen', false)
                ->assertSee('rel="canonical" href="'.$canonical.'"', false)
                ->assertSee('hreflang="x-default"', false)
                ->assertSee($canonical, false)
                ->assertSee('FAQPage', false)
                ->assertSee('/marketplace', false)
                ->assertSee('/register', false);
        }

        $this->assertDatabaseHas('blogs', [
            'slug' => $slug,
            'primary_locale' => 'de',
            'status' => 'published',
        ]);
    }

    public function test_europe_guest_post_guide_publishes_with_images_and_faq(): void
    {
        $this->seed(GastbeitraegeEuropaBlogSeeder::class);

        $slug = GastbeitraegeEuropaBlogPost::SLUG;
        $canonical = PublicI18n::urlForLocale('blog/'.$slug, 'de');

        $this->get('/de/blog/'.$slug)
            ->assertOk()
            ->assertSee('Gastbeiträge kaufen', false)
            ->assertSee('rel="canonical" href="'.$canonical.'"', false)
            ->assertSee('FAQPage', false)
            ->assertSee('/media/blogs/content/gastbeitraege-europa-checkliste.jpg', false)
            ->assertSee('/media/blogs/content/gastbeitraege-europa-sprachen.jpg', false)
            ->assertSee('gastbeitraege-europa-featured.jpg', false)
            ->assertSee('/marketplace', false);

        $this->assertDatabaseHas('blogs', [
            'slug' => $slug,
            'primary_locale' => 'de',
            'status' => 'published',
            'featured_image' => GastbeitraegeEuropaBlogPost::FEATURED_STORAGE,
        ]);

        $this->assertFileExists(public_path('assets/img/blog/gastbeitraege-europa-featured.jpg'));
        $this->assertFileExists(storage_path('app/public/'.GastbeitraegeEuropaBlogPost::FEATURED_STORAGE));
        $this->assertFileExists(storage_path('app/public/blogs/content/gastbeitraege-europa-checkliste.jpg'));
        $this->assertFileExists(storage_path('app/public/blogs/content/gastbeitraege-europa-sprachen.jpg'));
    }

    public function test_live_link_checklist_guide_publishes_with_images_internal_links_and_faq(): void
    {
        $this->seed(LiveLinkChecklistBlogSeeder::class);

        $slug = LiveLinkChecklistBlogPost::SLUG;
        $canonical = PublicI18n::urlForLocale('blog/'.$slug, 'en');

        $this->get('/blog/'.$slug)
            ->assertOk()
            ->assertSee('What to Check After the Live Link', false)
            ->assertSee('rel="canonical" href="'.$canonical.'"', false)
            ->assertSee('FAQPage', false)
            ->assertSee('/media/blogs/content/live-link-checklist-attributes.jpg', false)
            ->assertSee('/media/blogs/content/live-link-checklist-rankings.jpg', false)
            ->assertSee('live-link-checklist-featured.jpg', false)
            ->assertSee('/marketplace', false)
            ->assertSee('/register', false)
            ->assertSee('/how-it-works', false)
            ->assertSee('/pricing', false)
            ->assertSee('/faq', false)
            ->assertSee('/blog/gastbeitraege-kaufen-europa-publisher-sites-richtig-waehlen', false)
            ->assertSee('/blog/backlinks-aufbauen-die-echte-rankings-erzielen-nicht-nur-zahlen', false);

        $this->get('/fr/blog/'.$slug)
            ->assertOk()
            ->assertSee('rel="canonical" href="'.$canonical.'"', false);

        $this->assertDatabaseHas('blogs', [
            'slug' => $slug,
            'primary_locale' => 'en',
            'status' => 'published',
            'featured_image' => LiveLinkChecklistBlogPost::FEATURED_STORAGE,
        ]);

        $this->assertFileExists(public_path('assets/img/blog/live-link-checklist-featured.jpg'));
        $this->assertFileExists(storage_path('app/public/'.LiveLinkChecklistBlogPost::FEATURED_STORAGE));
        $this->assertFileExists(storage_path('app/public/blogs/content/live-link-checklist-attributes.jpg'));
        $this->assertFileExists(storage_path('app/public/blogs/content/live-link-checklist-rankings.jpg'));
    }

    public function test_advertiser_platform_guide_publishes_with_screenshots_and_faq(): void
    {
        $this->seed(AdvertiserPlatformGuideBlogSeeder::class);

        $slug = AdvertiserPlatformGuideBlogPost::SLUG;
        $canonical = PublicI18n::urlForLocale('blog/'.$slug, 'en');

        $this->get('/blog/'.$slug)
            ->assertOk()
            ->assertSee('How to Buy Guest Posts on SEOLinkBuildings', false)
            ->assertSee('rel="canonical" href="'.$canonical.'"', false)
            ->assertSee('FAQPage', false)
            ->assertSee('/media/blogs/content/howto-adv-dashboard.jpg', false)
            ->assertSee('/media/blogs/content/howto-adv-catalog.jpg', false)
            ->assertSee('/media/blogs/content/howto-adv-content-library.jpg', false)
            ->assertSee('/media/blogs/content/howto-adv-add-funds.jpg', false)
            ->assertSee('/media/blogs/content/howto-adv-orders.jpg', false)
            ->assertSee('howto-advertiser-featured.jpg', false)
            ->assertSee('/register', false)
            ->assertSee('/marketplace', false);

        $this->get('/de/blog/'.$slug)
            ->assertOk()
            ->assertSee('rel="canonical" href="'.$canonical.'"', false)
            ->assertSee('How to Buy Guest Posts on SEOLinkBuildings', false);

        $this->assertDatabaseHas('blogs', [
            'slug' => $slug,
            'primary_locale' => 'en',
            'status' => 'published',
            'featured_image' => AdvertiserPlatformGuideBlogPost::FEATURED_STORAGE,
        ]);
    }

    public function test_publisher_platform_guide_publishes_with_screenshots_and_faq(): void
    {
        $this->seed(PublisherPlatformGuideBlogSeeder::class);

        $slug = PublisherPlatformGuideBlogPost::SLUG;
        $canonical = PublicI18n::urlForLocale('blog/'.$slug, 'en');

        $this->get('/blog/'.$slug)
            ->assertOk()
            ->assertSee('Publisher Guide: Add Sites, Complete Orders, and Withdraw Earnings', false)
            ->assertSee('rel="canonical" href="'.$canonical.'"', false)
            ->assertSee('FAQPage', false)
            ->assertSee('/media/blogs/content/howto-pub-mysites.jpg', false)
            ->assertSee('/media/blogs/content/howto-pub-tasks.jpg', false)
            ->assertSee('/media/blogs/content/howto-pub-balance.jpg', false)
            ->assertSee('howto-publisher-featured.jpg', false)
            ->assertSee('/register', false);

        $this->get('/nl/blog/'.$slug)
            ->assertOk()
            ->assertSee('rel="canonical" href="'.$canonical.'"', false);

        $this->assertDatabaseHas('blogs', [
            'slug' => $slug,
            'primary_locale' => 'en',
            'status' => 'published',
            'featured_image' => PublisherPlatformGuideBlogPost::FEATURED_STORAGE,
        ]);
    }

    public function test_publisher_supply_posts_publish_with_faq_schema(): void
    {
        $this->seed(PublisherSupplyBlogsSeeder::class);

        foreach ([
            HowToPriceYourSiteBlogPost::SLUG,
            WhySitesGetRejectedBlogPost::SLUG,
            FasterPublisherPayoutsBlogPost::SLUG,
        ] as $slug) {
            $this->get('/blog/'.$slug)
                ->assertOk()
                ->assertSee('FAQPage', false);
        }
    }
}
