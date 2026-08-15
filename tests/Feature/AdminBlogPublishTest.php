<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\BlogTranslation;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminBlogPublishTest extends TestCase
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

    public function test_toggle_status_rejects_get_and_requires_post(): void
    {
        $admin = $this->adminUser();
        $blog = Blog::factory()->published()->create([
            'title' => 'Toggle Me',
            'slug' => 'toggle-me',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.blogs.toggle-status', $blog->id))
            ->assertMethodNotAllowed();

        $this->actingAs($admin)
            ->post(route('admin.blogs.toggle-status', $blog->id))
            ->assertRedirect(route('admin.blogs.index'))
            ->assertSessionHas('success');

        $this->assertSame('draft', $blog->fresh()->status);
    }

    public function test_republish_keeps_original_published_at(): void
    {
        $admin = $this->adminUser();
        $publishedAt = now()->subDays(10);
        $blog = Blog::factory()->published()->create([
            'title' => 'Dated Post',
            'slug' => 'dated-post',
            'published_at' => $publishedAt,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.blogs.toggle-status', $blog->id))
            ->assertRedirect(route('admin.blogs.index'));

        $unpublished = $blog->fresh();
        $this->assertSame('draft', $unpublished->status);
        $this->assertTrue($unpublished->published_at->isSameSecond($publishedAt));

        $this->actingAs($admin)
            ->post(route('admin.blogs.toggle-status', $blog->id))
            ->assertRedirect(route('admin.blogs.index'));

        $republished = $blog->fresh();
        $this->assertSame('published', $republished->status);
        $this->assertTrue($republished->published_at->isSameSecond($publishedAt));
    }

    public function test_store_sanitizes_script_tags_before_persist(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)->post(route('admin.blogs.store'), [
            'status' => 'published',
            'translations' => [
                'en' => [
                    'title' => 'Sanitized Store',
                    'slug' => 'sanitized-store',
                    'content' => '<p>Hello</p><script>alert(1)</script>',
                ],
            ],
        ])->assertRedirect(route('admin.blogs.index'));

        $blog = Blog::query()->where('slug', 'sanitized-store')->firstOrFail();
        $this->assertStringNotContainsString('<script', $blog->content);
        $this->assertStringContainsString('<p>Hello</p>', $blog->content);
    }

    public function test_public_show_does_not_render_stored_scripts(): void
    {
        $blog = Blog::factory()->published()->create([
            'title' => 'Public XSS',
            'slug' => 'public-xss',
            'content' => '<p>Visible</p><script>alert("xss")</script><p onclick="alert(1)">Click</p>',
        ]);
        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'en',
            'title' => 'Public XSS',
            'slug' => 'public-xss',
            'excerpt' => 'Excerpt',
            'content' => '<p>Visible</p><script>alert("xss")</script><p onclick="alert(1)">Click</p>',
            'is_published' => true,
        ]);

        $html = $this->get(route('blog.show', ['slug' => 'public-xss']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Visible', $html);
        $this->assertStringNotContainsString('<script>alert("xss")</script>', $html);
        $this->assertStringNotContainsString('onclick="alert(1)"', $html);
    }

    public function test_invalid_editor_image_upload_returns_422(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->postJson(route('admin.blogs.upload-image'), [
                'image' => UploadedFile::fake()->create('notes.txt', 20, 'text/plain'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
