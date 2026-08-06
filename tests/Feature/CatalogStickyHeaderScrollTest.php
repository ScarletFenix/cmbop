<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogStickyHeaderScrollTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_header_labels_and_sticky_scroll_contract(): void
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

        Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Sticky Header Site',
            'site_url' => 'https://sticky-header.example',
            'domain' => 'sticky-header.example',
            'da' => 40,
            'dr' => 45,
            'traffic' => 12000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'categories' => ['Technology'],
            'price' => 100,
            'publication_time' => 'permanent',
            'description' => 'Sticky catalog header regression site.',
            'link_type' => 'dofollow',
            'verified' => true,
            'active' => true,
        ]);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        foreach (['Site', 'Category', 'Traffic', 'DR', 'DA', 'Country', 'Buy'] as $label) {
            $this->assertMatchesRegularExpression(
                '/catalog-th[\s\S]{0,1200}?>[\s\S]{0,800}?'.preg_quote($label, '/').'/',
                $html,
                "Missing catalog header label: {$label}"
            );
        }

        $this->assertStringContainsString('About Traffic column', $html);
        $this->assertStringContainsString('glass-tip-trigger', $html);

        $css = (string) file_get_contents(public_path('assets/css/catalog.css'));

        // Headers lock under the shell topbar while the page scrolls.
        $this->assertMatchesRegularExpression(
            '/\.catalog-page \.table thead th \{[\s\S]*?position:\s*sticky;[\s\S]*?top:\s*calc\(\s*var\(--shell-topbar-height/',
            $css
        );

        // No nested table scroller: Bootstrap overflow-x:auto is overridden.
        $this->assertMatchesRegularExpression(
            '/\.catalog-table-scroll(?:,|\.table-responsive)[\s\S]*?overflow-x:\s*clip;[\s\S]*?overflow-y:\s*visible;/',
            $css
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.catalog-table-scroll[^{]*\{[^}]*overflow-x:\s*auto/',
            $css
        );

        // Buy must not be sticky-right: dual sticky + side shadow shook the
        // column and the header while scrolling the page vertically.
        $this->assertDoesNotMatchRegularExpression(
            '/\.catalog-th-action,\s*\.catalog-td-action\s*\{[^}]*position:\s*sticky/',
            $css
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.catalog-td-action[^{]*\{[^}]*position:\s*sticky/',
            $css
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.catalog-th-action[^{]*\{[^}]*right:\s*0/',
            $css
        );
        $this->assertDoesNotMatchRegularExpression(
            '/box-shadow:\s*-8px\s+0\s+12px/',
            $css
        );
    }
}
