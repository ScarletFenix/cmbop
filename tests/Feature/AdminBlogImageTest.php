<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminBlogImageTest extends TestCase
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

    public function test_admin_can_upload_content_image_via_editor_endpoint(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)
            ->postJson(route('admin.blogs.upload-image'), [
                'image' => UploadedFile::fake()->image('inline.jpg', 640, 360),
            ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
            ]);

        $url = $response->json('url');
        $this->assertIsString($url);
        $this->assertStringContainsString('/media/blogs/content/', $url);

        $relative = $this->blogDiskPathFromUrl($url);
        $this->assertNotSame('', $relative);
        Storage::disk('public')->assertExists($relative);

        if (function_exists('imagewebp')) {
            $this->assertStringEndsWith('.webp', $relative);
            $this->assertStringStartsWith('RIFF', Storage::disk('public')->get($relative));
        }
    }

    public function test_editor_gif_upload_stays_gif_and_uses_media_url(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)
            ->postJson(route('admin.blogs.upload-image'), [
                'image' => UploadedFile::fake()->image('inline.gif', 32, 32),
            ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
            ]);

        $url = $response->json('url');
        $this->assertIsString($url);
        $this->assertStringContainsString('/media/blogs/content/', $url);

        $relative = $this->blogDiskPathFromUrl($url);
        $this->assertStringEndsWith('.gif', $relative);
        Storage::disk('public')->assertExists($relative);
    }

    public function test_admin_can_remove_featured_image_on_update(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();

        $path = UploadedFile::fake()->image('featured.jpg')->store('blogs/featured', 'public');
        Storage::disk('public')->assertExists($path);

        $blog = Blog::create([
            'title' => 'Image Manage Post',
            'slug' => 'image-manage-post',
            'excerpt' => 'Excerpt',
            'content' => '<p>Body with text.</p>',
            'status' => 'published',
            'published_at' => now(),
            'featured_image' => $path,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->put(route('admin.blogs.update', $blog->id), [
                'title' => 'Image Manage Post',
                'excerpt' => 'Excerpt',
                'content' => '<p>Body with text.</p>',
                'status' => 'published',
                'remove_featured_image' => '1',
            ])
            ->assertRedirect(route('admin.blogs.index'));

        $blog->refresh();
        $this->assertNull($blog->featured_image);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_admin_can_replace_featured_image_on_update(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();

        $oldPath = UploadedFile::fake()->image('old-featured.jpg')->store('blogs/featured', 'public');

        $blog = Blog::create([
            'title' => 'Replace Featured Post',
            'slug' => 'replace-featured-post',
            'excerpt' => 'Excerpt',
            'content' => '<p>Body with text.</p>',
            'status' => 'draft',
            'featured_image' => $oldPath,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->put(route('admin.blogs.update', $blog->id), [
                'title' => 'Replace Featured Post',
                'excerpt' => 'Excerpt',
                'content' => '<p>Body with text.</p>',
                'status' => 'draft',
                'featured_image' => UploadedFile::fake()->image('new-featured.jpg', 800, 450),
            ])
            ->assertRedirect(route('admin.blogs.index'));

        $blog->refresh();
        $this->assertNotNull($blog->featured_image);
        $this->assertNotSame($oldPath, $blog->featured_image);
        $this->assertStringStartsWith('blogs/featured/', $blog->featured_image);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($blog->featured_image);

        if (function_exists('imagewebp')) {
            $this->assertStringEndsWith('.webp', $blog->featured_image);
            $this->assertStringStartsWith('RIFF', Storage::disk('public')->get($blog->featured_image));
        }

        $this->actingAs($admin)
            ->get(route('admin.blogs.edit', $blog->id))
            ->assertOk()
            ->assertSee('/media/'.$blog->featured_image, false);
    }

    public function test_admin_edit_page_wires_quill_upload_and_remove_featured_controls(): void
    {
        $admin = $this->adminUser();
        $blog = Blog::create([
            'title' => 'Edit Controls Post',
            'slug' => 'edit-controls-post',
            'excerpt' => 'Excerpt',
            'content' => '<p>Hello</p><p><img src="/storage/blogs/content/demo.jpg"></p>',
            'status' => 'draft',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $editHtml = $this->actingAs($admin)
            ->get(route('admin.blogs.edit', $blog->id))
            ->assertOk()
            ->assertSee('admin\/blogs\/upload-image', false)
            ->assertSee('admin\/blogs\/content-image', false)
            ->assertSee('remove_featured_image', false)
            ->assertSee('featuredImageRemoveBtn', false)
            ->assertSee('quillImageInput', false)
            ->assertSee('articleImagesManager', false)
            ->assertSee('Article images', false)
            ->assertSee('admin-blog-images.js', false)
            ->assertSee('/media/blogs/content/demo.jpg', false)
            ->assertDontSee('/storage/blogs/content/demo.jpg', false)
            ->getContent();
        $this->assertSame(1, substr_count($editHtml, 'new Quill('));

        $create = $this->actingAs($admin)
            ->get(route('admin.blogs.create'))
            ->assertOk()
            ->assertSee('admin\/blogs\/upload-image', false)
            ->assertSee('articleImagesManager', false)
            ->assertSee('quillImageInput', false);

        $this->assertSame(1, substr_count($create->getContent(), 'new Quill('));
    }

    public function test_admin_can_delete_stored_blog_content_image(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();

        $path = UploadedFile::fake()->image('inline-delete.jpg')->store('blogs/content', 'public');
        Storage::disk('public')->assertExists($path);

        $this->actingAs($admin)
            ->deleteJson(route('admin.blogs.delete-content-image'), [
                'url' => '/storage/'.$path,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        Storage::disk('public')->assertMissing($path);
    }

    public function test_admin_cannot_delete_bundled_curated_image(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();
        $path = 'blogs/content/gastbeitraege-europa-sprachen.jpg';
        Storage::disk('public')->put($path, 'bundled-bytes');

        $this->actingAs($admin)
            ->deleteJson(route('admin.blogs.delete-content-image'), [
                'url' => '/media/'.$path,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        Storage::disk('public')->assertExists($path);
    }

    public function test_admin_cannot_delete_image_still_referenced_by_a_post(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();
        $path = UploadedFile::fake()->image('shared-inline.jpg')->store('blogs/content', 'public');

        Blog::create([
            'title' => 'Still Uses Image',
            'slug' => 'still-uses-image',
            'excerpt' => 'Excerpt',
            'content' => '<p><img src="/media/'.$path.'"></p>',
            'status' => 'draft',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->deleteJson(route('admin.blogs.delete-content-image'), [
                'url' => '/media/'.$path,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        Storage::disk('public')->assertExists($path);
    }

    public function test_admin_cannot_delete_image_outside_blog_storage(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();

        $path = UploadedFile::fake()->image('other.jpg')->store('uploads/other', 'public');

        $this->actingAs($admin)
            ->deleteJson(route('admin.blogs.delete-content-image'), [
                'url' => '/storage/'.$path,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        Storage::disk('public')->assertExists($path);
    }

    public function test_admin_can_delete_content_image_via_media_url(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();

        $path = UploadedFile::fake()->image('inline-media.webp')->store('blogs/content', 'public');
        Storage::disk('public')->assertExists($path);

        $this->actingAs($admin)
            ->deleteJson(route('admin.blogs.delete-content-image'), [
                'url' => '/media/'.$path,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        Storage::disk('public')->assertMissing($path);
    }

    public function test_admin_can_delete_content_image_via_absolute_media_url_with_query(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();

        $path = UploadedFile::fake()->image('inline-abs.webp')->store('blogs/content', 'public');
        Storage::disk('public')->assertExists($path);

        $this->actingAs($admin)
            ->deleteJson(route('admin.blogs.delete-content-image'), [
                'url' => 'https://example.test/media/'.$path.'?cache=1#preview',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        Storage::disk('public')->assertMissing($path);
    }

    public function test_admin_blog_images_js_deletes_media_and_storage_urls(): void
    {
        $js = file_get_contents(public_path('assets/js/admin-blog-images.js'));

        $this->assertIsString($js);
        $this->assertStringContainsString('/media/blogs/', $js);
        $this->assertStringContainsString('/storage/blogs/', $js);
        $this->assertStringContainsString('isStoredBlogImageSrc', $js);
        $this->assertStringNotContainsString('maybeDeleteStoredFile(target.src)', $js);
        $this->assertStringNotContainsString('maybeDeleteStoredFile(src)', $js);
    }

    public function test_update_deletes_removed_inline_image_only_after_save(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();
        $path = UploadedFile::fake()->image('will-remove.jpg')->store('blogs/content', 'public');

        $blog = Blog::create([
            'title' => 'Inline Cleanup On Save',
            'slug' => 'inline-cleanup-on-save',
            'excerpt' => 'Excerpt',
            'content' => '<p><img src="/media/'.$path.'"></p>',
            'status' => 'draft',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);
        $blog->translations()->create([
            'locale' => 'en',
            'title' => 'Inline Cleanup On Save',
            'slug' => 'inline-cleanup-on-save',
            'excerpt' => 'Excerpt',
            'content' => '<p><img src="/media/'.$path.'"></p>',
            'is_published' => true,
        ]);

        Storage::disk('public')->assertExists($path);

        $this->actingAs($admin)
            ->put(route('admin.blogs.update', $blog->id), [
                'status' => 'draft',
                'translations' => [
                    'en' => [
                        'title' => 'Inline Cleanup On Save',
                        'slug' => 'inline-cleanup-on-save',
                        'content' => '<p>Image removed from the body.</p>',
                    ],
                ],
            ])
            ->assertRedirect(route('admin.blogs.index'));

        Storage::disk('public')->assertMissing($path);
    }

    public function test_featured_image_url_rewrites_absolute_storage_urls(): void
    {
        $blog = new Blog([
            'featured_image' => 'https://example.test/storage/blogs/featured/hero.jpg?cache=1',
        ]);

        $this->assertSame('/media/blogs/featured/hero.jpg', $blog->featuredImageUrl());
    }

    public function test_store_converts_featured_jpeg_to_webp(): void
    {
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('GD WebP not available');
        }

        Storage::fake('public');
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->post(route('admin.blogs.store'), [
                'status' => 'draft',
                'featured_image' => UploadedFile::fake()->image('hero.jpg', 800, 450),
                'translations' => [
                    'en' => [
                        'title' => 'WebP Featured Post',
                        'slug' => 'webp-featured-post',
                        'content' => '<p>Body with text.</p>',
                    ],
                ],
            ])
            ->assertRedirect(route('admin.blogs.index'));

        $blog = Blog::query()->where('slug', 'webp-featured-post')->first();
        $this->assertNotNull($blog);
        $this->assertNotNull($blog->featured_image);
        $this->assertStringStartsWith('blogs/featured/', $blog->featured_image);
        $this->assertStringEndsWith('.webp', $blog->featured_image);
        Storage::disk('public')->assertExists($blog->featured_image);
        $this->assertStringStartsWith('RIFF', Storage::disk('public')->get($blog->featured_image));
        $this->assertSame('/media/'.$blog->featured_image, $blog->featuredImageUrl());
    }

    private function blogDiskPathFromUrl(string $url): string
    {
        $relative = ltrim((string) (parse_url($url, PHP_URL_PATH) ?: ''), '/');
        $relative = preg_replace('#^(storage|media)/#', '', $relative) ?: '';

        return $relative;
    }
}
