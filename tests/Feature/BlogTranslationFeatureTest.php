<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\BlogTranslation;
use App\Models\Role;
use App\Models\User;
use App\Services\CuratedBlogSync;
use App\Support\GastbeitraegeEuropaBlogPost;
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

    public function test_show_resolves_translation_slug_when_blogs_slug_was_renamed(): void
    {
        $blog = Blog::factory()->published()->create([
            'title' => 'English title',
            'slug' => 'renamed-pillar-slug',
            'content' => '<p>English body</p>',
            'primary_locale' => 'de',
        ]);

        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'en',
            'title' => 'English title',
            'slug' => 'english-title-renamed-pillar',
            'excerpt' => 'English excerpt',
            'content' => '<p>English body</p>',
            'is_published' => true,
        ]);

        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'de',
            'title' => 'Deutscher Titel',
            'slug' => 'deutscher-titel-renamed-pillar',
            'excerpt' => 'Deutscher Auszug',
            'content' => '<p>Deutscher Inhalt</p>',
            'is_published' => true,
        ]);

        $this->get('/de/blog/deutscher-titel-renamed-pillar')
            ->assertOk()
            ->assertSee('Deutscher Titel', false)
            ->assertSee('Deutscher Inhalt', false);

        $this->get('/blog/deutscher-titel-renamed-pillar')
            ->assertOk()
            ->assertSee('English title', false)
            ->assertSee('English body', false);

        $this->get('/blog/renamed-pillar-slug')
            ->assertOk()
            ->assertSee('English title', false);
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

    public function test_canonical_url_falls_back_to_primary_locale_when_english_is_missing(): void
    {
        $blog = Blog::factory()->published()->create([
            'title' => 'English leftover title',
            'slug' => 'english-leftover-canonical',
            'content' => '<p>English leftover body</p>',
            'primary_locale' => 'de',
        ]);
        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'de',
            'title' => 'Deutscher Titel',
            'slug' => 'deutscher-canonical-titel',
            'excerpt' => 'Auszug',
            'content' => '<p>Deutscher Inhalt</p>',
            'is_published' => true,
        ]);

        $this->assertSame(
            url('/de/blog/deutscher-canonical-titel'),
            $blog->canonicalUrl('en')
        );
    }

    public function test_admin_can_create_english_only_post_when_other_locales_submit_empty_quill_html(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)->post(route('admin.blogs.store'), [
            'status' => 'published',
            'translations' => [
                'en' => [
                    'title' => 'English Only Title',
                    'slug' => 'english-only-title',
                    'excerpt' => 'English excerpt',
                    'content' => '<p>English body</p>',
                ],
                'de' => [
                    'title' => '',
                    'slug' => '',
                    'excerpt' => '',
                    'content' => '<p><br></p>',
                ],
                'fr' => [
                    'title' => '',
                    'slug' => '',
                    'excerpt' => '',
                    'content' => '<p></p>',
                ],
                'nl' => [
                    'title' => '',
                    'slug' => '',
                    'excerpt' => '',
                    'content' => '',
                ],
            ],
        ])->assertRedirect(route('admin.blogs.index'));

        $blog = Blog::query()->where('slug', 'english-only-title')->firstOrFail();
        $this->assertDatabaseHas('blog_translations', [
            'blog_id' => $blog->id,
            'locale' => 'en',
            'title' => 'English Only Title',
        ]);
        $this->assertDatabaseMissing('blog_translations', ['blog_id' => $blog->id, 'locale' => 'de']);
        $this->assertDatabaseMissing('blog_translations', ['blog_id' => $blog->id, 'locale' => 'fr']);
        $this->assertDatabaseMissing('blog_translations', ['blog_id' => $blog->id, 'locale' => 'nl']);
    }

    public function test_admin_can_update_english_only_post_when_other_locales_submit_empty_quill_html(): void
    {
        $admin = $this->adminUser();
        $blog = Blog::factory()->published()->create([
            'title' => 'Existing English',
            'slug' => 'existing-english',
            'content' => '<p>Original</p>',
        ]);
        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'en',
            'title' => 'Existing English',
            'slug' => 'existing-english',
            'excerpt' => 'Excerpt',
            'content' => '<p>Original</p>',
            'is_published' => true,
        ]);

        $this->actingAs($admin)->put(route('admin.blogs.update', $blog->id), [
            'status' => 'published',
            'translations' => [
                'en' => [
                    'title' => 'Existing English',
                    'slug' => 'existing-english',
                    'excerpt' => 'Excerpt',
                    'content' => '<p>Updated body</p>',
                ],
                'de' => [
                    'title' => '',
                    'content' => '<p><br></p>',
                ],
                'fr' => [
                    'title' => '',
                    'content' => '<p></p>',
                ],
                'nl' => [
                    'title' => '',
                    'content' => '',
                ],
            ],
        ])->assertRedirect(route('admin.blogs.index'));

        $this->assertDatabaseHas('blog_translations', [
            'blog_id' => $blog->id,
            'locale' => 'en',
            'content' => '<p>Updated body</p>',
        ]);
        $this->assertDatabaseMissing('blog_translations', ['blog_id' => $blog->id, 'locale' => 'de']);
    }

    public function test_create_page_hydrates_quill_from_old_input_and_escapes_script_tags(): void
    {
        $admin = $this->adminUser();

        $html = $this->actingAs($admin)
            ->from(route('admin.blogs.create'))
            ->followingRedirects()
            ->post(route('admin.blogs.store'), [
                'status' => 'published',
                'translations' => [
                    'en' => [
                        'title' => '',
                        'content' => '<p>Keep this body</p></script><script>alert(1)</script>',
                    ],
                ],
            ])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="existingContent-en"', $html);
        $this->assertStringContainsString('Keep this body', $html);
        $this->assertStringContainsString('\\u003Cp\\u003E', $html);
        $this->assertStringNotContainsString('alert(1)', $html);
        $this->assertStringNotContainsString('</script><script>alert(1)</script>', $html);
    }

    public function test_edit_page_escapes_script_tags_in_existing_content_json(): void
    {
        $admin = $this->adminUser();
        $blog = Blog::factory()->create([
            'title' => 'XSS Edit',
            'slug' => 'xss-edit',
            'content' => '<p>Safe</p></script><script>alert(1)</script>',
        ]);
        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'en',
            'title' => 'XSS Edit',
            'slug' => 'xss-edit',
            'excerpt' => 'Excerpt',
            'content' => '<p>Safe</p></script><script>alert(1)</script>',
            'is_published' => true,
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.blogs.edit', $blog->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="existingContent-en"', $html);
        $this->assertStringContainsString('\\u003Cp\\u003E', $html);
        $this->assertStringContainsString('Safe', $html);
        $this->assertStringNotContainsString('alert(1)', $html);
        $this->assertStringNotContainsString(
            'id="existingContent-en"><p>Safe</p></script><script>alert(1)</script>',
            $html
        );
    }

    public function test_admin_persists_meta_and_can_unpublish_or_remove_a_locale(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)->post(route('admin.blogs.store'), [
            'status' => 'published',
            'translations' => [
                'en' => [
                    'title' => 'Meta English',
                    'slug' => 'meta-english',
                    'excerpt' => 'English excerpt',
                    'meta_title' => 'Custom EN title',
                    'meta_description' => 'Custom EN description',
                    'content' => '<p>English body</p>',
                    'is_published' => '1',
                ],
                'de' => [
                    'title' => 'Meta Deutsch',
                    'slug' => 'meta-deutsch',
                    'excerpt' => 'Deutscher Auszug',
                    'content' => '<p>Deutscher Inhalt</p>',
                    'is_published' => '1',
                ],
            ],
        ])->assertRedirect(route('admin.blogs.index'));

        $blog = Blog::query()->where('slug', 'meta-english')->firstOrFail();
        $this->assertDatabaseHas('blog_translations', [
            'blog_id' => $blog->id,
            'locale' => 'en',
            'meta_title' => 'Custom EN title',
            'meta_description' => 'Custom EN description',
        ]);

        $this->get('/blog/meta-english')
            ->assertOk()
            ->assertSee('Custom EN title — SEOLinkBuildings', false)
            ->assertSee('Custom EN description', false);

        $this->actingAs($admin)->put(route('admin.blogs.update', $blog->id), [
            'status' => 'published',
            'translations' => [
                'en' => [
                    'title' => 'Meta English',
                    'slug' => 'meta-english',
                    'excerpt' => 'English excerpt',
                    'meta_title' => 'Custom EN title',
                    'meta_description' => 'Custom EN description',
                    'content' => '<p>English body</p>',
                    'is_published' => '1',
                ],
                'de' => [
                    'title' => 'Meta Deutsch',
                    'slug' => 'meta-deutsch',
                    'excerpt' => 'Deutscher Auszug',
                    'content' => '<p>Deutscher Inhalt</p>',
                    'is_published' => '0',
                ],
            ],
        ])->assertRedirect(route('admin.blogs.index'));

        $this->assertDatabaseHas('blog_translations', [
            'blog_id' => $blog->id,
            'locale' => 'de',
            'is_published' => false,
        ]);

        $this->actingAs($admin)->put(route('admin.blogs.update', $blog->id), [
            'status' => 'published',
            'translations' => [
                'en' => [
                    'title' => 'Meta English',
                    'slug' => 'meta-english',
                    'content' => '<p>English body</p>',
                    'is_published' => '1',
                ],
                'de' => [
                    'title' => '',
                    'content' => '<p><br></p>',
                ],
            ],
        ])->assertRedirect(route('admin.blogs.index'));

        $this->assertDatabaseMissing('blog_translations', [
            'blog_id' => $blog->id,
            'locale' => 'de',
        ]);
    }

    public function test_admin_index_links_published_posts_to_the_live_url(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->get(route('admin.blogs.index'))
            ->assertOk()
            ->assertSee('View live', false)
            ->assertSee('fa-external-link', false);
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

    public function test_translation_backfill_does_not_steal_another_blogs_public_slug(): void
    {
        $other = Blog::factory()->published()->create([
            'title' => 'Other Host',
            'slug' => 'other-heal-host',
            'content' => '<p>Other body</p>',
        ]);
        BlogTranslation::create([
            'blog_id' => $other->id,
            'locale' => 'en',
            'title' => 'Other Host',
            'slug' => 'hello-heal-base',
            'excerpt' => 'Excerpt',
            'content' => '<p>Other body</p>',
            'is_published' => true,
        ]);

        $older = Blog::factory()->published()->create([
            'title' => 'Older Heal Post',
            'slug' => 'hello-heal-base',
            'content' => '<p>Older body</p>',
        ]);
        $newer = Blog::factory()->published()->create([
            'title' => 'Newer Heal Post',
            'slug' => 'hello-heal-base-1',
            'content' => '<p>Newer body</p>',
        ]);

        CuratedBlogSync::backfillTranslationsFromBlogs();

        $this->assertNotSame(
            'hello-heal-base-1',
            $older->translations()->where('locale', 'en')->value('slug')
        );

        $html = $this->get('/blog/hello-heal-base-1')
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<h1[^>]*>\s*Newer Heal Post\s*<\/h1>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<h1[^>]*>\s*Older Heal Post\s*<\/h1>/', $html);
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

    public function test_english_sitemap_includes_de_primary_post_without_en_translation(): void
    {
        $this->artisan('blog:upsert-gastbeitraege-europa')->assertSuccessful();

        $blog = Blog::query()->where('slug', GastbeitraegeEuropaBlogPost::SLUG)->firstOrFail();
        $this->assertFalse(
            $blog->translations()->where('locale', 'en')->exists(),
            'Fixture must stay DE-only so the sitemap fallback is actually tested.'
        );

        $this->get('/sitemap-en.xml')
            ->assertOk()
            ->assertSee('/blog/'.GastbeitraegeEuropaBlogPost::SLUG, false);

        $this->get('/sitemap-de.xml')
            ->assertOk()
            ->assertSee('/de/blog/'.GastbeitraegeEuropaBlogPost::SLUG, false);
    }

    public function test_sitemap_fallback_does_not_reuse_another_posts_translation_slug(): void
    {
        $listed = Blog::factory()->published()->create([
            'title' => 'Listed English Post',
            'slug' => 'listed-english-post',
            'content' => '<p>Listed body</p>',
        ]);
        BlogTranslation::create([
            'blog_id' => $listed->id,
            'locale' => 'en',
            'title' => 'Listed English Post',
            'slug' => 'shared-fallback-slug',
            'excerpt' => 'Excerpt',
            'content' => '<p>Listed body</p>',
            'is_published' => true,
        ]);

        $deOnly = Blog::factory()->published()->create([
            'title' => 'DE-only fallback post',
            'slug' => 'shared-fallback-slug',
            'content' => '<p>German body</p>',
            'primary_locale' => 'de',
        ]);
        BlogTranslation::create([
            'blog_id' => $deOnly->id,
            'locale' => 'de',
            'title' => 'Nur Deutscher Beitrag',
            'slug' => 'nur-deutscher-beitrag',
            'excerpt' => 'Auszug',
            'content' => '<p>Deutscher Inhalt</p>',
            'is_published' => true,
        ]);

        $enSitemap = $this->get('/sitemap-en.xml')->assertOk()->getContent();
        $this->assertSame(1, substr_count($enSitemap, '/blog/shared-fallback-slug'));
        $this->assertStringContainsString('/blog/nur-deutscher-beitrag', $enSitemap);

        $html = $this->get('/blog/shared-fallback-slug')
            ->assertOk()
            ->getContent();
        $this->assertMatchesRegularExpression('/<h1[^>]*>\s*Listed English Post\s*<\/h1>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<h1[^>]*>\s*Nur Deutscher Beitrag\s*<\/h1>/', $html);
    }
}
