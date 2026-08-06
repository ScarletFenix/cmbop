<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogNewBadgeAlignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    private function advertiser(): User
    {
        $role = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }

    private function makeSite(array $overrides = []): Site
    {
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();
        $publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $publisher->roles()->attach($publisherRole->id);

        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'New Badge Site',
            'site_url' => 'https://new-badge.example',
            'domain' => 'new-badge.example',
            'da' => 40,
            'dr' => 45,
            'traffic' => 12000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'categories' => ['News'],
            'price' => 80,
            'publication_time' => 'permanent',
            'description' => 'NEW badge alignment and red pulse regression site.',
            'link_type' => 'dofollow',
            'verified' => true,
            'active' => true,
            'created_at' => now()->subDays(2),
            'custom_discount_percent' => 15,
            'custom_discount_starts_at' => now()->subDay(),
            'custom_discount_ends_at' => now()->addDays(5),
            'bulk_discount_enabled' => true,
            'bulk_discount_percent' => 10,
        ], $overrides));
    }

    public function test_discount_and_status_chips_stay_on_one_nowrap_row(): void
    {
        $this->makeSite();

        $html = $this->actingAs($this->advertiser())
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('catalog-site-title-row', $html);
        $this->assertStringContainsString('catalog-site-badges', $html);
        $this->assertStringContainsString('site-chip--sale', $html);
        $this->assertStringContainsString('site-chip--verified', $html);
        $this->assertStringContainsString('site-badge-new', $html);

        // The old flex-wrap row is what pushed Verified/NEW under the discount.
        $this->assertStringNotContainsString('d-flex align-items-center gap-2 flex-wrap', $html);

        $css = (string) file_get_contents(public_path('assets/css/catalog.css'));
        $this->assertStringContainsString('.catalog-site-title-row', $css);
        $this->assertStringContainsString('flex-wrap: nowrap', $css);
    }

    public function test_new_badge_uses_notification_red_pulse_without_beep(): void
    {
        $this->makeSite();

        $html = $this->actingAs($this->advertiser())
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('site-badge-new__pulse', $html);

        $css = (string) file_get_contents(public_path('assets/css/catalog.css'));
        $this->assertStringContainsString('--brand-danger, #dc2626', $css);
        $this->assertStringContainsString('siteNewRing', $css);
        $this->assertStringContainsString('siteNewPulse', $css);
        $this->assertStringNotContainsString('#ef4444', $css);
        $this->assertStringNotContainsString('siteNewAlertPop', $css);

        $js = (string) file_get_contents(public_path('assets/js/catalog.js'));
        $this->assertStringNotContainsString('catalogNewBadgeBeeped', $js);
        $this->assertStringNotContainsString('PulseBadge.playBeep', $js);
        // Alignment row styles remain so discount chips do not wrap Verified/NEW.
        $this->assertStringContainsString('flex-wrap: nowrap', $css);
    }
}
