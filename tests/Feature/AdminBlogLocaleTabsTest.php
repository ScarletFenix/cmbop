<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\BlogTranslation;
use App\Models\Role;
use App\Models\User;
use App\Support\PublicI18n;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminBlogLocaleTabsTest extends TestCase
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

    public function test_create_form_shows_all_supported_locale_tabs(): void
    {
        $html = $this->actingAs($this->adminUser())
            ->get(route('admin.blogs.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('locale-pane-en', $html);
        $this->assertStringContainsString('UK', $html);
        foreach (['us', 'es', 'it', 'de', 'fr', 'nl'] as $locale) {
            $this->assertStringContainsString('locale-pane-'.$locale, $html);
            $this->assertStringContainsString('quillEditor-'.$locale, $html);
        }

        foreach (PublicI18n::supported() as $locale) {
            $this->assertStringContainsString('"'.$locale.'"', $html);
        }
    }

    public function test_admin_can_save_spanish_and_us_translations(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->post(route('admin.blogs.store'), [
                'status' => 'published',
                'primary_locale' => 'es',
                'translations' => [
                    'en' => [
                        'title' => 'UK English title',
                        'slug' => 'uk-english-title',
                        'excerpt' => 'UK excerpt',
                        'content' => '<p>UK body</p>',
                    ],
                    'es' => [
                        'title' => 'Título en español',
                        'slug' => 'titulo-en-espanol',
                        'excerpt' => 'Extracto',
                        'content' => '<p>Cuerpo en español</p>',
                    ],
                    'us' => [
                        'title' => 'US English title',
                        'slug' => 'us-english-title',
                        'excerpt' => 'US excerpt',
                        'content' => '<p>US body</p>',
                    ],
                ],
            ])
            ->assertRedirect(route('admin.blogs.index'));

        $blog = Blog::query()->where('slug', 'uk-english-title')->first();
        $this->assertNotNull($blog);
        $this->assertSame('es', $blog->primary_locale);

        $this->assertDatabaseHas('blog_translations', [
            'blog_id' => $blog->id,
            'locale' => 'es',
            'title' => 'Título en español',
            'slug' => 'titulo-en-espanol',
        ]);
        $this->assertDatabaseHas('blog_translations', [
            'blog_id' => $blog->id,
            'locale' => 'us',
            'title' => 'US English title',
        ]);

        $this->assertSame(3, BlogTranslation::query()->where('blog_id', $blog->id)->count());
    }
}
