<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\Role;
use App\Models\User;
use App\Services\CuratedBlogSync;
use App\Support\AcheterGuestPostsFrBlogPost;
use App\Support\AdvertiserPlatformGuideBlogPost;
use App\Support\ChoosePublisherSiteBlogPost;
use App\Support\FasterPublisherPayoutsBlogPost;
use App\Support\GastbeitraegeEuropaBlogPost;
use App\Support\GuestPostsEuropeEnBlogPost;
use App\Support\GuestPostsUkUsBlogPost;
use App\Support\HowToPriceYourSiteBlogPost;
use App\Support\LiveLinkChecklistBlogPost;
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

        foreach (CuratedBlogSync::curatedSlugs() as $slug) {
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
            ->assertSee('Wallet, Escrow', false);
    }

    public function test_blog_upsert_curated_command_inserts_posts(): void
    {
        Blog::query()->delete();

        $this->artisan('blog:upsert-curated')->assertSuccessful();

        $this->assertGreaterThanOrEqual(count(CuratedBlogSync::curatedSlugs()), Blog::query()->count());
        $this->assertTrue(Blog::query()->where('slug', LiveLinkChecklistBlogPost::SLUG)->exists());
        $this->assertTrue(Blog::query()->where('slug', AdvertiserPlatformGuideBlogPost::SLUG)->exists());
        $this->assertTrue(Blog::query()->where('slug', PublisherPlatformGuideBlogPost::SLUG)->exists());
        $this->assertTrue(Blog::query()->where('slug', ChoosePublisherSiteBlogPost::SLUG)->exists());
        $this->assertTrue(Blog::query()->where('slug', WalletEscrowRefundsBlogPost::SLUG)->exists());
        $this->assertTrue(Blog::query()->where('slug', GuestPostsEuropeEnBlogPost::SLUG)->exists());
        $this->assertTrue(Blog::query()->where('slug', AcheterGuestPostsFrBlogPost::SLUG)->exists());
        $this->assertTrue(Blog::query()->where('slug', GuestPostsUkUsBlogPost::SLUG)->exists());
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

        $europeEn = Blog::query()->where('slug', GuestPostsEuropeEnBlogPost::SLUG)->first();
        $this->assertNotNull($europeEn);
        $this->assertSame('en', $europeEn->primary_locale);
        $this->assertStringContainsString('/storage/blogs/content/market-guest-posts-europe-en-languages.jpg', $europeEn->content);
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
            ->assertOk()
            ->assertSee('Gastbeiträge kaufen', false)
            ->assertSee('Wallet, Escrow', false);

        foreach (CuratedBlogSync::curatedSlugs() as $slug) {
            $this->assertDatabaseHas('blogs', [
                'slug' => $slug,
                'status' => 'published',
            ]);
            $this->get(route('blog.show', ['slug' => $slug]))->assertOk();
        }

        $this->assertTrue(
            Blog::query()->where('slug', GuestPostsEuropeEnBlogPost::SLUG)->exists()
        );
        $this->assertTrue(
            Blog::query()->where('slug', AcheterGuestPostsFrBlogPost::SLUG)->exists()
        );
    }

    public function test_sync_does_not_overwrite_manually_edited_curated_post(): void
    {
        $this->artisan('blog:upsert-live-link-checklist')->assertSuccessful();

        $admin = $this->adminUser();
        $blog = Blog::query()->where('slug', LiveLinkChecklistBlogPost::SLUG)->firstOrFail();
        $originalTitle = $blog->title;

        $this->actingAs($admin)->put(route('admin.blogs.update', $blog->id), [
            'status' => 'published',
            'translations' => [
                'en' => [
                    'title' => 'Admin Edited Checklist Title',
                    'slug' => $blog->slug,
                    'excerpt' => 'Edited excerpt',
                    'content' => '<p>Edited body</p>',
                ],
            ],
        ])->assertRedirect(route('admin.blogs.index'));

        $this->assertSame('Admin Edited Checklist Title', $blog->fresh()->title);
        $this->assertNotNull($blog->fresh()->manually_edited_at);

        $this->actingAs($admin)
            ->post(route('admin.blogs.sync-curated'))
            ->assertRedirect(route('admin.blogs.index'));

        $this->assertSame('Admin Edited Checklist Title', $blog->fresh()->title);
        $this->assertNotSame($originalTitle, $blog->fresh()->title);
    }

    public function test_deleted_curated_post_is_not_resurrected_by_heal_or_sync(): void
    {
        $this->artisan('blog:upsert-live-link-checklist')->assertSuccessful();

        $admin = $this->adminUser();
        $blog = Blog::query()->where('slug', LiveLinkChecklistBlogPost::SLUG)->firstOrFail();

        $this->actingAs($admin)
            ->delete(route('admin.blogs.destroy', $blog->id))
            ->assertRedirect(route('admin.blogs.index'));

        $this->assertDatabaseMissing('blogs', ['slug' => LiveLinkChecklistBlogPost::SLUG]);
        $this->assertDatabaseHas('curated_blog_tombstones', ['slug' => LiveLinkChecklistBlogPost::SLUG]);

        Cache::forget('curated_blogs_present_v1');
        CuratedBlogSync::ensurePresent();

        $this->assertDatabaseMissing('blogs', ['slug' => LiveLinkChecklistBlogPost::SLUG]);

        $this->actingAs($admin)
            ->post(route('admin.blogs.sync-curated'))
            ->assertRedirect(route('admin.blogs.index'));

        $this->assertDatabaseMissing('blogs', ['slug' => LiveLinkChecklistBlogPost::SLUG]);
    }

    public function test_sync_button_asks_for_confirmation(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->get(route('admin.blogs.index'))
            ->assertOk()
            ->assertSee('data-slb-confirm=', false)
            ->assertSee('Posts you edited are kept', false);
    }
}
