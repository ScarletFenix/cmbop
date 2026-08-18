<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\SiteUrlReveal;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

/**
 * Catalog eye-reveal refresh calls safe_external_url() for sample post links.
 * Composer "files" autoload is enough after dump-autoload, but a deploy that
 * only synced PHP must still boot the helper via AppServiceProvider.
 */
class SafeExternalUrlHelperBootTest extends TestCase
{
    use RefreshDatabase;

    public function test_safe_external_url_is_available_after_app_boot(): void
    {
        $this->assertTrue(function_exists('safe_external_url'));
        $this->assertTrue(function_exists('safe_href_url'));
        $this->assertSame('https://example.com/post', safe_external_url('https://example.com/post'));
        $this->assertSame('#', safe_external_url('javascript:alert(1)'));
        $this->assertSame('https://example.com/post', safe_href_url('https://example.com/post'));
        $this->assertSame('/advertiser/content-submissions/5', safe_href_url('/advertiser/content-submissions/5'));
        $this->assertNull(safe_href_url('javascript:alert(1)'));
        $this->assertNull(safe_href_url(''));
    }

    public function test_appservice_provider_requires_url_helper(): void
    {
        $boot = file_get_contents((new ReflectionClass(AppServiceProvider::class))->getFileName());
        $this->assertStringContainsString("app_path('Helpers/UrlHelper.php')", $boot);
    }

    public function test_revealed_catalog_row_with_sample_url_renders_after_refresh(): void
    {
        $this->seed(RolesTableSeeder::class);

        $advertiserRole = Role::where('name', 'advertiser')->firstOrFail();
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();

        $advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $advertiser->roles()->attach($advertiserRole->id);

        $publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $publisher->roles()->attach($publisherRole->id);

        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Reveal Sample',
            'site_url' => 'https://reveal-sample.example',
            'domain' => 'reveal-sample.example',
            'example_url' => 'https://reveal-sample.example/guest-post-demo',
            'da' => 40,
            'dr' => 45,
            'traffic' => 12000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'categories' => ['News'],
            'price' => 80,
            'publication_time' => 'permanent',
            'description' => 'Catalog reveal sample URL regression site.',
            'link_type' => 'dofollow',
            'verified' => true,
            'active' => true,
        ]);

        SiteUrlReveal::create([
            'user_id' => $advertiser->id,
            'site_id' => $site->id,
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertSee('reveal-sample.example', false)
            ->assertSee('https://reveal-sample.example/guest-post-demo', false)
            ->assertDontSee('Call to undefined function safe_external_url', false);
    }
}
