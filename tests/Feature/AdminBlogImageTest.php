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
        $this->assertStringContainsString('/storage/blogs/content/', $url);

        $relative = ltrim(parse_url($url, PHP_URL_PATH) ?: '', '/');
        $relative = preg_replace('#^storage/#', '', $relative) ?: '';
        $this->assertNotSame('', $relative);
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
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($blog->featured_image);
    }

    public function test_admin_edit_page_wires_quill_upload_and_remove_featured_controls(): void
    {
        $admin = $this->adminUser();
        $blog = Blog::create([
            'title' => 'Edit Controls Post',
            'slug' => 'edit-controls-post',
            'excerpt' => 'Excerpt',
            'content' => '<p>Hello</p>',
            'status' => 'draft',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.blogs.edit', $blog->id))
            ->assertOk()
            ->assertSee('admin\/blogs\/upload-image', false)
            ->assertSee('remove_featured_image', false)
            ->assertSee('featuredImageRemoveBtn', false)
            ->assertSee('quillImageInput', false);

        $this->actingAs($admin)
            ->get(route('admin.blogs.create'))
            ->assertOk()
            ->assertSee('admin\/blogs\/upload-image', false)
            ->assertSee('quillImageInput', false);
    }
}
