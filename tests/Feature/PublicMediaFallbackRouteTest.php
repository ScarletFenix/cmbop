<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicMediaFallbackRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_route_streams_site_image_from_public_disk(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('sites/fallback-cover.webp', 'fake-webp-body');

        $this->get('/media/sites/fallback-cover.webp')
            ->assertOk()
            ->assertHeader('Cache-Control');
    }

    public function test_media_route_streams_blog_image_from_public_disk(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('blogs/featured/hero.webp', 'fake-webp-body');
        Storage::disk('public')->put('blogs/content/inline.webp', 'fake-webp-body');

        $this->get('/media/blogs/featured/hero.webp')
            ->assertOk()
            ->assertHeader('Cache-Control');
        $this->get('/media/blogs/content/inline.webp')
            ->assertOk();
    }

    public function test_staff_sites_media_route_streams_for_authenticated_admin(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('sites/staff-cover.webp', 'fake-webp-body');

        $this->seed(RolesTableSeeder::class);
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $admin->roles()->attach($adminRole->id);

        $this->actingAs($admin)
            ->get('/admin/sites/media/sites/staff-cover.webp')
            ->assertOk();
    }

    public function test_media_route_rejects_path_traversal_and_private_prefixes(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('sites/ok.webp', 'x');
        Storage::disk('public')->put('private/secret.txt', 'nope');

        $this->get('/media/../sites/ok.webp')->assertNotFound();
        $this->get('/media/private/secret.txt')->assertNotFound();
        $this->get('/media/sites/missing.webp')->assertNotFound();
        $this->get('/media/sites/%2e%2e/private/secret.txt')->assertNotFound();
        $this->get('/media/blogs/content/%2e%2e/%2e%2e/private/secret.txt')->assertNotFound();
    }
}
