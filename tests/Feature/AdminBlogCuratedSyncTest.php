<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\Role;
use App\Models\User;
use App\Support\AdvertiserPlatformGuideBlogPost;
use App\Support\AiAeoGuestPostsBlogPost;
use App\Support\BacklinksAufbauenBlogPost;
use App\Support\ChoosePublisherSiteBlogPost;
use App\Support\DofollowNofollowAnkertexteBlogPost;
use App\Support\FasterPublisherPayoutsBlogPost;
use App\Support\GastbeitraegeEuropaBlogPost;
use App\Support\GuestPostBriefBlogPost;
use App\Support\HowToPriceYourSiteBlogPost;
use App\Support\LiveLinkChecklistBlogPost;
use App\Support\LiveLinkRemovedBlogPost;
use App\Support\MarketplaceVsOutreachBlogPost;
use App\Support\PublisherPlatformGuideBlogPost;
use App\Support\WalletEscrowRefundsBlogPost;
use App\Support\WhySitesGetRejectedBlogPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AdminBlogCuratedSyncTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        $role = Role::firstOrCreate(['name' => 'admin']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }

    public function test_admin_can_sync_curated_blogs_into_manageable_list(): void
    {
        Blog::query()->delete();

        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->post(route('admin.blogs.sync-curated'))
            ->assertRedirect(route('admin.blogs.index'))
            ->assertSessionHas('success');

        foreach ([
            BacklinksAufbauenBlogPost::SLUG,
            GastbeitraegeEuropaBlogPost::SLUG,
            DofollowNofollowAnkertexteBlogPost::SLUG,
            LiveLinkChecklistBlogPost::SLUG,
            AdvertiserPlatformGuideBlogPost::SLUG,
            PublisherPlatformGuideBlogPost::SLUG,
            ChoosePublisherSiteBlogPost::SLUG,
            WalletEscrowRefundsBlogPost::SLUG,
            LiveLinkRemovedBlogPost::SLUG,
            GuestPostBriefBlogPost::SLUG,
            MarketplaceVsOutreachBlogPost::SLUG,
            AiAeoGuestPostsBlogPost::SLUG,
            HowToPriceYourSiteBlogPost::SLUG,
            WhySitesGetRejectedBlogPost::SLUG,
            FasterPublisherPayoutsBlogPost::SLUG,
        ] as $slug) {
            $this->assertDatabaseHas('blogs', [
                'slug' => $slug,
                'status' => 'published',
            ]);
        }

        $this->actingAs($admin)
            ->get(route('admin.blogs.index'))
            ->assertOk()
            ->assertSee('Sync curated SEO blogs', false)
            ->assertSee('Gastbeiträge kaufen', false)
            ->assertSee('DoFollow', false)
            ->assertSee('What to Check After the Live Link', false)
            ->assertSee('How to Buy Guest Posts', false)
            ->assertSee('Publisher Guide', false)
            ->assertSee('Wallet, Escrow', false)
            ->assertSee('How to Choose a Publisher Site', false)
            ->assertSee('How to Price Your Site', false);
    }

    public function test_blog_upsert_curated_command_inserts_posts(): void
    {
        Blog::query()->delete();

        $this->artisan('blog:upsert-curated')->assertSuccessful();

        $this->assertGreaterThanOrEqual(15, Blog::query()->count());
        $this->assertTrue(Blog::query()->where('slug', LiveLinkChecklistBlogPost::SLUG)->exists());
        $this->assertTrue(Blog::query()->where('slug', AdvertiserPlatformGuideBlogPost::SLUG)->exists());
        $this->assertTrue(Blog::query()->where('slug', PublisherPlatformGuideBlogPost::SLUG)->exists());
        $this->assertTrue(Blog::query()->where('slug', ChoosePublisherSiteBlogPost::SLUG)->exists());
        $this->assertTrue(Blog::query()->where('slug', WalletEscrowRefundsBlogPost::SLUG)->exists());
        $this->assertTrue(Blog::query()->where('slug', HowToPriceYourSiteBlogPost::SLUG)->exists());
        $this->assertTrue(Blog::query()->where('slug', WhySitesGetRejectedBlogPost::SLUG)->exists());
        $this->assertTrue(Blog::query()->where('slug', FasterPublisherPayoutsBlogPost::SLUG)->exists());

        $europe = Blog::query()->where('slug', GastbeitraegeEuropaBlogPost::SLUG)->first();
        $this->assertNotNull($europe);
        $this->assertStringContainsString('/storage/blogs/content/gastbeitraege-europa-sprachen.jpg', $europe->content);
        $this->assertStringNotContainsString('/assets/img/blog/', $europe->content);
        $this->assertFileExists(storage_path('app/public/blogs/content/gastbeitraege-europa-sprachen.jpg'));

        $adv = Blog::query()->where('slug', AdvertiserPlatformGuideBlogPost::SLUG)->first();
        $this->assertNotNull($adv);
        $this->assertStringContainsString('/storage/blogs/content/howto-adv-catalog.jpg', $adv->content);

        $wallet = Blog::query()->where('slug', WalletEscrowRefundsBlogPost::SLUG)->first();
        $this->assertNotNull($wallet);
        $this->assertStringContainsString('/storage/blogs/content/trust-wallet-escrow-inline.jpg', $wallet->content);
        $this->assertFileExists(storage_path('app/public/blogs/content/trust-wallet-escrow-inline.jpg'));

        $price = Blog::query()->where('slug', HowToPriceYourSiteBlogPost::SLUG)->first();
        $this->assertNotNull($price);
        $this->assertStringContainsString('/storage/blogs/content/supply-price-site-inline.jpg', $price->content);
        $this->assertFileExists(storage_path('app/public/blogs/content/supply-price-site-inline.jpg'));
    }

    public function test_public_blog_show_heals_legacy_asset_img_paths(): void
    {
        $this->artisan('blog:upsert-gastbeitraege-europa')->assertSuccessful();

        $blog = Blog::query()->where('slug', GastbeitraegeEuropaBlogPost::SLUG)->firstOrFail();
        $blog->content = str_replace(
            '/storage/blogs/content/',
            '/assets/img/blog/',
            $blog->content
        );
        $blog->save();

        Cache::forget('curated_blogs_present_v1');
        Cache::forget('curated_blogs_inline_storage_v1');

        $this->get('/de/blog/'.$blog->slug)
            ->assertOk()
            ->assertSee('/storage/blogs/content/gastbeitraege-europa-sprachen.jpg', false)
            ->assertDontSee('/assets/img/blog/gastbeitraege-europa-sprachen.jpg', false);

        $blog->refresh();
        $this->assertStringContainsString('/storage/blogs/content/', $blog->content);
        $this->assertStringNotContainsString('/assets/img/blog/', $blog->content);
    }

    public function test_public_blog_index_auto_syncs_missing_curated_posts(): void
    {
        Blog::query()->delete();
        Cache::forget('curated_blogs_present_v1');

        $this->get(route('blog.index'))
            ->assertOk();

        $this->assertGreaterThanOrEqual(15, Blog::query()->count());

        // Full curated set is 15 posts (index paginates 12). Assert presence in
        // DB and that each public show route renders.
        foreach ([
            BacklinksAufbauenBlogPost::SLUG,
            GastbeitraegeEuropaBlogPost::SLUG,
            DofollowNofollowAnkertexteBlogPost::SLUG,
            LiveLinkChecklistBlogPost::SLUG,
            AdvertiserPlatformGuideBlogPost::SLUG,
            PublisherPlatformGuideBlogPost::SLUG,
            ChoosePublisherSiteBlogPost::SLUG,
            WalletEscrowRefundsBlogPost::SLUG,
            LiveLinkRemovedBlogPost::SLUG,
            GuestPostBriefBlogPost::SLUG,
            MarketplaceVsOutreachBlogPost::SLUG,
            AiAeoGuestPostsBlogPost::SLUG,
            HowToPriceYourSiteBlogPost::SLUG,
            WhySitesGetRejectedBlogPost::SLUG,
            FasterPublisherPayoutsBlogPost::SLUG,
        ] as $slug) {
            $this->assertDatabaseHas('blogs', [
                'slug' => $slug,
                'status' => 'published',
            ]);
            $this->get(route('blog.show', ['slug' => $slug]))->assertOk();
        }
    }
}
