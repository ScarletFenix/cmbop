<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\BlogSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminBlogAuthTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    public function test_guest_is_redirected_from_admin_blogs(): void
    {
        $this->get(route('admin.blogs.index'))
            ->assertRedirect(route('login'));
    }

    public function test_advertiser_and_publisher_cannot_access_admin_blogs(): void
    {
        foreach (['advertiser', 'publisher'] as $role) {
            $this->actingAs($this->userWithRole($role))
                ->get(route('admin.blogs.index'))
                ->assertForbidden();
        }
    }

    public function test_marketing_is_redirected_away_from_admin_blogs(): void
    {
        $this->actingAs($this->userWithRole('marketing'))
            ->get(route('admin.blogs.index'))
            ->assertRedirect();
    }

    public function test_non_admin_cannot_mutate_blogs(): void
    {
        $blog = Blog::factory()->create();
        $advertiser = $this->userWithRole('advertiser');

        $this->actingAs($advertiser)
            ->post(route('admin.blogs.store'), [
                'status' => 'draft',
                'translations' => [
                    'en' => [
                        'title' => 'Nope',
                        'content' => '<p>Nope</p>',
                    ],
                ],
            ])
            ->assertForbidden();

        $this->actingAs($advertiser)
            ->put(route('admin.blogs.update', $blog->id), [
                'status' => 'draft',
                'translations' => [
                    'en' => [
                        'title' => 'Nope',
                        'content' => '<p>Nope</p>',
                    ],
                ],
            ])
            ->assertForbidden();

        $this->actingAs($advertiser)
            ->post(route('admin.blogs.toggle-status', $blog->id))
            ->assertForbidden();

        $this->actingAs($advertiser)
            ->delete(route('admin.blogs.destroy', $blog->id))
            ->assertForbidden();
    }

    public function test_blog_seeder_creates_english_translations(): void
    {
        $this->seed(RolesTableSeeder::class);
        $this->seed(BlogSeeder::class);

        $this->assertDatabaseHas('blog_translations', [
            'locale' => 'en',
            'slug' => 'how-to-build-high-quality-backlinks-in-2026',
        ]);
    }
}
