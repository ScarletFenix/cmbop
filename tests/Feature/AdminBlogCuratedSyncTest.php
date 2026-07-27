<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\Role;
use App\Models\User;
use App\Support\BacklinksAufbauenBlogPost;
use App\Support\DofollowNofollowAnkertexteBlogPost;
use App\Support\GastbeitraegeEuropaBlogPost;
use App\Support\LiveLinkChecklistBlogPost;
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
            ->assertSee('What to Check After the Live Link', false);
    }

    public function test_blog_upsert_curated_command_inserts_posts(): void
    {
        Blog::query()->delete();

        $this->artisan('blog:upsert-curated')->assertSuccessful();

        $this->assertGreaterThanOrEqual(4, Blog::query()->count());
        $this->assertTrue(Blog::query()->where('slug', LiveLinkChecklistBlogPost::SLUG)->exists());
    }

    public function test_public_blog_index_auto_syncs_missing_curated_posts(): void
    {
        Blog::query()->delete();
        Cache::forget('curated_blogs_present_v1');

        $this->get(route('blog.index'))
            ->assertOk()
            ->assertSee('Gastbeiträge kaufen', false)
            ->assertSee('DoFollow', false)
            ->assertSee('What to Check After the Live Link', false)
            ->assertSee('Backlinks aufbauen', false);

        foreach ([
            BacklinksAufbauenBlogPost::SLUG,
            GastbeitraegeEuropaBlogPost::SLUG,
            DofollowNofollowAnkertexteBlogPost::SLUG,
            LiveLinkChecklistBlogPost::SLUG,
        ] as $slug) {
            $this->assertDatabaseHas('blogs', [
                'slug' => $slug,
                'status' => 'published',
            ]);
        }
    }
}
