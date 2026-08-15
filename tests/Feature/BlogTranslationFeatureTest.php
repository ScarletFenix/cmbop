<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\BlogTranslation;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BlogTranslationFeatureTest extends TestCase
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

    public function test_locale_slug_renders_translated_post(): void
    {
        $blog = Blog::factory()->published()->create([
            'title' => 'English title',
            'slug' => 'english-title',
            'content' => '<p>English body</p>',
        ]);

        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'en',
            'title' => 'English title',
            'slug' => 'english-title',
            'excerpt' => 'English excerpt',
            'content' => '<p>English body</p>',
            'is_published' => true,
        ]);

        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'de',
            'title' => 'Deutscher Titel',
            'slug' => 'deutscher-titel',
            'excerpt' => 'Deutscher Auszug',
            'content' => '<p>Deutscher Inhalt</p>',
            'is_published' => true,
        ]);

        $this->get('/de/blog/deutscher-titel')
            ->assertOk()
            ->assertSee('Deutscher Titel', false)
            ->assertSee('Deutscher Inhalt', false)
            ->assertSee('hreflang="de"', false)
            ->assertSee('hreflang="en-GB"', false)
            ->assertSee('hreflang="en-GB" href="'.url('/blog/english-title').'"', false)
            ->assertSee('hreflang="de" href="'.url('/de/blog/deutscher-titel').'"', false)
            ->assertDontSee('hreflang="en-GB" href="'.url('/blog/deutscher-titel').'"', false);
    }

    public function test_missing_locale_translation_falls_back_to_english_notice(): void
    {
        $blog = Blog::factory()->published()->create([
            'title' => 'English title',
            'slug' => 'english-fallback',
            'content' => '<p>English body</p>',
            'primary_locale' => 'en',
        ]);

        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'en',
            'title' => 'English title',
            'slug' => 'english-fallback',
            'excerpt' => 'English excerpt',
            'content' => '<p>English body</p>',
            'is_published' => true,
        ]);

        $this->get('/de/blog/english-fallback')
            ->assertOk()
            ->assertSee('English title', false)
            ->assertSee('translation is not yet available', false)
            ->assertSee('rel="canonical" href="'.url('/blog/english-fallback').'"', false);
    }

    public function test_admin_can_create_and_update_translations_without_overwriting_others(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)->post(route('admin.blogs.store'), [
            'status' => 'published',
            'translations' => [
                'en' => [
                    'title' => 'English Admin Title',
                    'slug' => 'english-admin-title',
                    'excerpt' => 'English excerpt',
                    'content' => '<p>English body</p>',
                ],
                'de' => [
                    'title' => 'Deutscher Admin Titel',
                    'slug' => 'deutscher-admin-titel',
                    'excerpt' => 'Deutscher Auszug',
                    'content' => '<p>Deutscher Inhalt</p>',
                ],
            ],
        ])->assertRedirect(route('admin.blogs.index'));

        $blog = Blog::query()->where('slug', 'english-admin-title')->firstOrFail();
        $this->assertDatabaseHas('blog_translations', ['blog_id' => $blog->id, 'locale' => 'en', 'title' => 'English Admin Title']);
        $this->assertDatabaseHas('blog_translations', ['blog_id' => $blog->id, 'locale' => 'de', 'title' => 'Deutscher Admin Titel']);

        $this->actingAs($admin)->put(route('admin.blogs.update', $blog->id), [
            'status' => 'published',
            'translations' => [
                'en' => [
                    'title' => 'English Admin Title',
                    'slug' => 'english-admin-title',
                    'excerpt' => 'English excerpt',
                    'content' => '<p>English body</p>',
                ],
                'de' => [
                    'title' => 'Geaenderter Deutscher Titel',
                    'slug' => 'geaenderter-deutscher-titel',
                    'excerpt' => 'Neuer Auszug',
                    'content' => '<p>Aktualisierter Inhalt</p>',
                ],
            ],
        ])->assertRedirect(route('admin.blogs.index'));

        $this->assertDatabaseHas('blog_translations', ['blog_id' => $blog->id, 'locale' => 'en', 'title' => 'English Admin Title']);
        $this->assertDatabaseHas('blog_translations', ['blog_id' => $blog->id, 'locale' => 'de', 'title' => 'Geaenderter Deutscher Titel']);
    }

    public function test_public_blog_heals_missing_translations_table(): void
    {
        $blog = Blog::factory()->published()->create([
            'title' => 'Heal Me Post',
            'slug' => 'heal-me-post',
            'excerpt' => 'Excerpt for heal',
            'content' => '<p>Heal body</p>',
            'primary_locale' => 'en',
            'published_at' => now(),
        ]);

        Schema::dropIfExists('blog_translations');
        $this->assertFalse(Schema::hasTable('blog_translations'));

        // Index auto-syncs curated posts; keep Heal Me newest so it stays on page 1.
        $response = $this->get('/blog')->assertOk();
        Blog::query()->where('id', '!=', $blog->id)->update(['published_at' => now()->subDay()]);
        $blog->update(['published_at' => now()]);

        $this->get('/blog')
            ->assertOk()
            ->assertSee('Heal Me Post', false);
        unset($response);

        $this->assertTrue(Schema::hasTable('blog_translations'));
        $this->assertDatabaseHas('blog_translations', [
            'blog_id' => $blog->id,
            'locale' => 'en',
            'slug' => 'heal-me-post',
        ]);

        $this->get('/blog/heal-me-post')
            ->assertOk()
            ->assertSee('Heal Me Post', false);

        $this->get('/sitemap-en.xml')
            ->assertOk()
            ->assertSee('/blog/heal-me-post', false);
    }

    public function test_sitemap_contains_only_localized_blog_urls(): void
    {
        $blog = Blog::factory()->published()->create([
            'title' => 'Localized Post',
            'slug' => 'localized-post',
            'content' => '<p>Body</p>',
        ]);

        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'en',
            'title' => 'Localized Post',
            'slug' => 'localized-post',
            'excerpt' => 'Excerpt',
            'content' => '<p>Body</p>',
            'is_published' => true,
        ]);

        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'de',
            'title' => 'Lokalisierter Beitrag',
            'slug' => 'lokalisierter-beitrag',
            'excerpt' => 'Auszug',
            'content' => '<p>Inhalt</p>',
            'is_published' => true,
        ]);

        $this->get('/sitemap-en.xml')
            ->assertOk()
            ->assertSee('/blog/localized-post', false)
            ->assertSee('/de/blog/lokalisierter-beitrag', false)
            ->assertDontSee('/de/blog/localized-post', false);

        $this->get('/sitemap-de.xml')
            ->assertOk()
            ->assertSee('/de/blog/lokalisierter-beitrag', false)
            ->assertSee('/blog/localized-post', false)
            ->assertDontSee('/de/blog/localized-post', false);
    }
}
