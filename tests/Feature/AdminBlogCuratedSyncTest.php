<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\BlogTranslation;
use App\Models\Role;
use App\Models\User;
use App\Services\CuratedBlogSync;
use App\Services\CuratedBlogWriter;
use App\Support\AcheterGuestPostsFrBlogPost;
use App\Support\AdvertiserPlatformGuideBlogPost;
use App\Support\ChoosePublisherSiteBlogPost;
use App\Support\CuratedBlogCatalog;
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
use Illuminate\Support\Facades\Storage;
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

    public function test_admin_blogs_index_resolves_curated_catalog(): void
    {
        $admin = $this->adminUser();

        $this->assertSame(
            CuratedBlogCatalog::slugs(),
            \App\Services\CuratedBlogCatalog::slugs()
        );

        $this->actingAs($admin)
            ->get(route('admin.blogs.index'))
            ->assertOk()
            ->assertSee('Blogs', false)
            ->assertViewHas('blogs');
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
            ->assertSee('/media/blogs/content/gastbeitraege-europa-sprachen.jpg', false)
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

    public function test_ensure_present_republishes_missing_featured_images(): void
    {
        Storage::fake('public');
        $this->artisan('blog:upsert-gastbeitraege-europa')->assertSuccessful();

        $path = GastbeitraegeEuropaBlogPost::FEATURED_STORAGE;
        Storage::disk('public')->assertExists($path);
        Storage::disk('public')->delete($path);
        Storage::disk('public')->assertMissing($path);

        Cache::forget('curated_blogs_present_v1');
        Cache::forget('curated_blogs_inline_storage_v1');

        CuratedBlogSync::ensurePresent();

        Storage::disk('public')->assertExists($path);
    }

    public function test_upsert_updates_row_found_by_curated_key_without_duplicating(): void
    {
        $this->artisan('blog:upsert-live-link-checklist')->assertSuccessful();

        $blog = Blog::query()->where('slug', LiveLinkChecklistBlogPost::SLUG)->firstOrFail();
        $blog->forceFill([
            'slug' => 'live-link-checklist-renamed',
            'manually_edited_at' => null,
        ])->save();

        $this->artisan('blog:upsert-live-link-checklist')->assertSuccessful();

        $this->assertSame(1, Blog::query()->where('curated_key', LiveLinkChecklistBlogPost::SLUG)->count());
        $this->assertTrue(
            Blog::query()
                ->where('id', $blog->id)
                ->where('slug', LiveLinkChecklistBlogPost::SLUG)
                ->exists()
        );
    }

    public function test_upsert_refreshes_stale_primary_translation_content(): void
    {
        $this->artisan('blog:upsert-gastbeitraege-europa')->assertSuccessful();

        $blog = Blog::query()->where('slug', GastbeitraegeEuropaBlogPost::SLUG)->firstOrFail();
        $locale = $blog->primary_locale ?: 'de';
        $translation = $blog->translations()->where('locale', $locale)->firstOrFail();

        $translation->forceFill(['content' => '<p>Stale translation body</p>'])->save();
        $blog->forceFill([
            'content' => '<p>Stale blogs body</p>',
            'manually_edited_at' => null,
        ])->save();

        $this->artisan('blog:upsert-gastbeitraege-europa')->assertSuccessful();

        $blog->refresh();
        $translation->refresh();
        $this->assertStringNotContainsString('Stale blogs body', (string) $blog->content);
        $this->assertStringNotContainsString('Stale translation body', (string) $translation->content);
        $this->assertSame($blog->content, $translation->content);
    }

    public function test_toggle_unpublish_is_not_reverted_by_curated_sync(): void
    {
        $this->artisan('blog:upsert-live-link-checklist')->assertSuccessful();

        $admin = $this->adminUser();
        $blog = Blog::query()->where('slug', LiveLinkChecklistBlogPost::SLUG)->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.blogs.toggle-status', $blog->id))
            ->assertRedirect(route('admin.blogs.index'));

        $this->assertSame('draft', $blog->fresh()->status);
        $this->assertNotNull($blog->fresh()->manually_edited_at);

        $this->actingAs($admin)
            ->post(route('admin.blogs.sync-curated'))
            ->assertRedirect(route('admin.blogs.index'));

        $this->assertSame('draft', $blog->fresh()->status);
    }

    public function test_upsert_preserves_draft_without_manually_edited_at(): void
    {
        $this->artisan('blog:upsert-live-link-checklist')->assertSuccessful();

        $blog = Blog::query()->where('slug', LiveLinkChecklistBlogPost::SLUG)->firstOrFail();
        $blog->forceFill([
            'status' => 'draft',
            'manually_edited_at' => null,
        ])->save();

        $this->artisan('blog:upsert-live-link-checklist')->assertSuccessful();

        $this->assertSame('draft', $blog->fresh()->status);
    }

    public function test_upsert_prefers_curated_key_when_another_post_took_the_slug(): void
    {
        $this->artisan('blog:upsert-live-link-checklist')->assertSuccessful();

        $pillar = Blog::query()->where('slug', LiveLinkChecklistBlogPost::SLUG)->firstOrFail();
        $pillar->forceFill([
            'slug' => 'live-link-checklist-renamed',
            'manually_edited_at' => null,
        ])->save();

        $intruder = Blog::factory()->published()->create([
            'title' => 'Custom Hijack',
            'slug' => LiveLinkChecklistBlogPost::SLUG,
            'content' => '<p>Do not overwrite me.</p>',
        ]);

        $this->artisan('blog:upsert-live-link-checklist')->assertSuccessful();

        $this->assertSame('Custom Hijack', $intruder->fresh()->title);
        $this->assertSame(LiveLinkChecklistBlogPost::SLUG, $intruder->fresh()->slug);
        $this->assertSame('live-link-checklist-renamed', $pillar->fresh()->slug);
        $this->assertSame(1, Blog::query()->where('curated_key', LiveLinkChecklistBlogPost::SLUG)->count());
    }

    public function test_upsert_does_not_overwrite_custom_post_that_reused_a_catalog_slug(): void
    {
        $slug = LiveLinkChecklistBlogPost::SLUG;
        Blog::query()->where('slug', $slug)->orWhere('curated_key', $slug)->get()->each->delete();

        $custom = Blog::factory()->published()->create([
            'title' => 'Custom Occupying Catalog Slug',
            'slug' => $slug,
            'content' => '<p>Leave this custom article alone.</p>',
            'manually_edited_at' => now(),
            'curated_key' => null,
        ]);

        $this->artisan('blog:upsert-live-link-checklist')->assertSuccessful();

        $this->assertSame('Custom Occupying Catalog Slug', $custom->fresh()->title);
        $this->assertSame($slug, $custom->fresh()->slug);
        $this->assertNull($custom->fresh()->curated_key);

        $pillar = Blog::query()->where('curated_key', $slug)->first();
        $this->assertNotNull($pillar);
        $this->assertNotSame($custom->id, $pillar->id);
        $this->assertNotSame($slug, $pillar->slug);
        $this->assertStringContainsString('What to Check After the Live Link', (string) $pillar->title);
    }

    public function test_ensure_present_creates_missing_pillar_when_custom_post_occupies_slug(): void
    {
        $slug = LiveLinkChecklistBlogPost::SLUG;
        Blog::query()->where('slug', $slug)->orWhere('curated_key', $slug)->get()->each->delete();

        Blog::factory()->published()->create([
            'title' => 'Custom Occupying Catalog Slug',
            'slug' => $slug,
            'content' => '<p>Custom body.</p>',
            'manually_edited_at' => now(),
            'curated_key' => null,
        ]);

        Cache::forget('curated_blogs_present_v1');
        $this->get(route('blog.index'))->assertOk();

        $this->assertSame('Custom Occupying Catalog Slug', Blog::query()->where('slug', $slug)->value('title'));
        $this->assertTrue(Blog::query()->where('curated_key', $slug)->exists());
        $this->assertNotSame(
            $slug,
            Blog::query()->where('curated_key', $slug)->value('slug')
        );
    }

    public function test_deleting_custom_post_that_reused_catalog_slug_does_not_tombstone_pillar(): void
    {
        $slug = LiveLinkChecklistBlogPost::SLUG;
        Blog::query()->where('slug', $slug)->orWhere('curated_key', $slug)->get()->each->delete();

        $custom = Blog::factory()->published()->create([
            'title' => 'Custom Occupying Catalog Slug',
            'slug' => $slug,
            'content' => '<p>Custom body.</p>',
            'manually_edited_at' => now(),
            'curated_key' => null,
        ]);

        $this->actingAs($this->adminUser())
            ->delete(route('admin.blogs.destroy', $custom->id))
            ->assertRedirect(route('admin.blogs.index'));

        $this->assertDatabaseMissing('curated_blog_tombstones', ['slug' => $slug]);

        $this->artisan('blog:upsert-live-link-checklist')->assertSuccessful();

        $this->assertTrue(Blog::query()->where('curated_key', $slug)->exists());
    }

    public function test_uniquified_pillar_keeps_faq_schema_on_public_page(): void
    {
        $slug = LiveLinkChecklistBlogPost::SLUG;
        Blog::query()->where('slug', $slug)->orWhere('curated_key', $slug)->get()->each->delete();

        Blog::factory()->published()->create([
            'title' => 'Custom Occupying Catalog Slug',
            'slug' => $slug,
            'content' => '<p>Custom body.</p>',
            'manually_edited_at' => now(),
            'curated_key' => null,
        ]);

        $this->artisan('blog:upsert-live-link-checklist')->assertSuccessful();

        $pillar = Blog::query()->where('curated_key', $slug)->firstOrFail();
        $this->assertNotSame($slug, $pillar->slug);

        $this->get('/blog/'.$pillar->slug)
            ->assertOk()
            ->assertSee('FAQPage', false)
            ->assertSee('What to Check After the Live Link', false);
    }

    public function test_public_html_rewrites_catalog_slug_links_to_uniquified_pillar(): void
    {
        $slug = LiveLinkChecklistBlogPost::SLUG;
        Blog::query()->where('slug', $slug)->orWhere('curated_key', $slug)->get()->each->delete();

        Blog::factory()->published()->create([
            'title' => 'Custom Occupying Catalog Slug',
            'slug' => $slug,
            'content' => '<p>Custom body.</p>',
            'manually_edited_at' => now(),
            'curated_key' => null,
        ]);

        $this->artisan('blog:upsert-live-link-checklist')->assertSuccessful();
        $pillar = Blog::query()->where('curated_key', $slug)->firstOrFail();

        $linker = Blog::factory()->published()->create([
            'title' => 'Linker Post',
            'slug' => 'linker-to-checklist',
            'content' => '<p><a href="/blog/'.$slug.'">Checklist</a></p>',
        ]);
        BlogTranslation::create([
            'blog_id' => $linker->id,
            'locale' => 'en',
            'title' => 'Linker Post',
            'slug' => 'linker-to-checklist',
            'excerpt' => 'Excerpt',
            'content' => '<p><a href="/blog/'.$slug.'">Checklist</a></p>',
            'is_published' => true,
        ]);

        $this->get('/blog/linker-to-checklist')
            ->assertOk()
            ->assertSee('href="/blog/'.$pillar->slug.'"', false)
            ->assertDontSee('href="/blog/'.$slug.'"', false);
    }

    public function test_custom_post_occupying_catalog_slug_does_not_inherit_pillar_faq(): void
    {
        $slug = LiveLinkChecklistBlogPost::SLUG;
        Blog::query()->where('slug', $slug)->orWhere('curated_key', $slug)->get()->each->delete();

        Blog::factory()->published()->create([
            'title' => 'Custom Occupying Catalog Slug',
            'slug' => $slug,
            'content' => '<p>Custom body without pillar FAQ.</p>',
            'manually_edited_at' => now(),
            'curated_key' => null,
        ]);

        $this->get('/blog/'.$slug)
            ->assertOk()
            ->assertSee('Custom Occupying Catalog Slug', false)
            ->assertDontSee('FAQPage', false);
    }

    public function test_sync_primary_translation_uniquifies_when_locale_suffix_is_taken(): void
    {
        $other = Blog::factory()->published()->create([
            'slug' => 'other-translation-host',
        ]);
        BlogTranslation::create([
            'blog_id' => $other->id,
            'locale' => 'de',
            'title' => 'Taken base',
            'slug' => 'collision-slug',
            'excerpt' => 'Excerpt',
            'content' => '<p>Taken base</p>',
            'is_published' => true,
        ]);
        BlogTranslation::create([
            'blog_id' => $other->id,
            'locale' => 'en',
            'title' => 'Taken suffix',
            'slug' => 'collision-slug-en',
            'excerpt' => 'Excerpt',
            'content' => '<p>Taken suffix</p>',
            'is_published' => true,
        ]);

        $blog = Blog::factory()->published()->create([
            'title' => 'Needs a free translation slug',
            'slug' => 'collision-slug',
            'content' => '<p>Body</p>',
            'primary_locale' => 'en',
        ]);

        CuratedBlogWriter::syncPrimaryTranslation($blog);

        $translationSlug = $blog->translations()->where('locale', 'en')->value('slug');
        $this->assertNotSame('collision-slug', $translationSlug);
        $this->assertNotSame('collision-slug-en', $translationSlug);
        $this->assertSame('collision-slug-en-1', $translationSlug);
    }
}
