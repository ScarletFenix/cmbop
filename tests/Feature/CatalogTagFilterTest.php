<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Catalog\CatalogFilterStatus;
use App\Services\Catalog\CatalogUrlQuery;
use App\Support\SiteTag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CatalogTagFilterTest extends TestCase
{
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => 'advertiser'],
            ['guard_name' => 'web']
        );
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->sync([$role->id]);

        return $user;
    }

    private function publisher(): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => 'publisher'],
            ['guard_name' => 'web']
        );
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->sync([$role->id]);

        return $user;
    }

    private function site(User $publisher, array $extra = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Tag Filter Site',
            'site_url' => 'https://tag-filter-'.uniqid().'.test',
            'domain' => 'tag-filter-'.uniqid().'.test',
            'da' => 40,
            'dr' => 45,
            'traffic' => 12000,
            'country' => 'de',
            'countries' => ['de'],
            'language' => 'de',
            'languages' => ['de'],
            'category' => 'marketing',
            'price' => 100,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Tag filter fixture',
            'verified' => true,
            'active' => true,
            'sponsored' => false,
            'partner_material' => false,
            'as_you_prefer' => false,
        ], $extra));
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_tag_param_is_allowlisted(): void
    {
        $this->assertContains('tag', CatalogUrlQuery::KEYS);
        $this->assertContains('tag', CatalogFilterStatus::QUERY_KEYS);
    }

    public function test_catalog_tag_select_uses_glossary(): void
    {
        $html = $this->actingAs($this->advertiser())
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="catalogTagFilter"', $html);
        $this->assertStringContainsString('name="tag"', $html);
        $this->assertStringContainsString('Partner article', $html);
        $this->assertStringContainsString('No tags', $html);
        $this->assertStringContainsString('All tags', $html);
        $this->assertStringContainsString(SiteTag::FILTER_TOOLTIP, $html);
        $this->assertStringNotContainsString('Sponsored Only', $html);
        $this->assertStringNotContainsString('name="sponsored"', $html);
    }

    public function test_tag_sponsored_filters_catalog(): void
    {
        $publisher = $this->publisher();
        $this->site($publisher, [
            'site_name' => 'Sponsored Listing',
            'sponsored' => true,
        ]);
        $this->site($publisher, [
            'site_name' => 'Partner Listing',
            'partner_material' => true,
        ]);
        $this->site($publisher, [
            'site_name' => 'Untagged Listing',
        ]);

        $html = $this->actingAs($this->advertiser())
            ->get(route('advertiser.catalog', ['tag' => 'sponsored']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Sponsored Listing', $html);
        $this->assertStringNotContainsString('Partner Listing', $html);
        $this->assertStringNotContainsString('Untagged Listing', $html);
        $this->assertStringContainsString('site-chip--sponsored', $html);
    }

    public function test_tag_partner_and_none_and_prefer(): void
    {
        $publisher = $this->publisher();
        $this->site($publisher, [
            'site_name' => 'Partner Listing',
            'partner_material' => true,
        ]);
        $this->site($publisher, [
            'site_name' => 'Prefer Listing',
            'as_you_prefer' => true,
        ]);
        $this->site($publisher, [
            'site_name' => 'Untagged Listing',
        ]);

        $advertiser = $this->advertiser();

        $partner = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', ['tag' => 'partner_material']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Partner Listing', $partner);
        $this->assertStringNotContainsString('Prefer Listing', $partner);
        $this->assertStringNotContainsString('Untagged Listing', $partner);

        $prefer = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', ['tag' => 'as_you_prefer']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Prefer Listing', $prefer);
        $this->assertStringNotContainsString('Partner Listing', $prefer);

        $none = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', ['tag' => 'none']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Untagged Listing', $none);
        $this->assertStringNotContainsString('Partner Listing', $none);
        $this->assertStringNotContainsString('Prefer Listing', $none);
    }

    public function test_tag_filter_uses_exclusive_winner_for_leftover_flags(): void
    {
        $publisher = $this->publisher();
        $this->site($publisher, [
            'site_name' => 'All Flags Leftover',
            'sponsored' => true,
            'partner_material' => true,
            'as_you_prefer' => true,
        ]);
        $this->site($publisher, [
            'site_name' => 'Partner Plus Prefer Leftover',
            'partner_material' => true,
            'as_you_prefer' => true,
        ]);
        $this->site($publisher, [
            'site_name' => 'Prefer Only',
            'as_you_prefer' => true,
        ]);
        $this->site($publisher, [
            'site_name' => 'Untagged Only',
        ]);

        $advertiser = $this->advertiser();

        $sponsored = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', ['tag' => 'sponsored']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('All Flags Leftover', $sponsored);
        $this->assertStringNotContainsString('Partner Plus Prefer Leftover', $sponsored);
        $this->assertStringNotContainsString('Prefer Only', $sponsored);
        $this->assertStringNotContainsString('Untagged Only', $sponsored);

        $partner = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', ['tag' => 'partner_material']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Partner Plus Prefer Leftover', $partner);
        $this->assertStringNotContainsString('All Flags Leftover', $partner);
        $this->assertStringNotContainsString('Prefer Only', $partner);
        $this->assertStringNotContainsString('Untagged Only', $partner);

        $prefer = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', ['tag' => 'as_you_prefer']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Prefer Only', $prefer);
        $this->assertStringNotContainsString('All Flags Leftover', $prefer);
        $this->assertStringNotContainsString('Partner Plus Prefer Leftover', $prefer);
        $this->assertStringNotContainsString('Untagged Only', $prefer);
        $this->assertStringContainsString('site-chip--prefer', $prefer);
        $this->assertStringNotContainsString('site-chip--sponsored', $prefer);
        $this->assertStringNotContainsString('site-chip--partner', $prefer);

        $none = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', ['tag' => 'none']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Untagged Only', $none);
        $this->assertStringNotContainsString('All Flags Leftover', $none);
        $this->assertStringNotContainsString('Partner Plus Prefer Leftover', $none);
        $this->assertStringNotContainsString('Prefer Only', $none);
    }

    public function test_sponsored_one_aliases_to_sponsored_tag(): void
    {
        $publisher = $this->publisher();
        $this->site($publisher, [
            'site_name' => 'Legacy Sponsored',
            'sponsored' => true,
        ]);
        $this->site($publisher, [
            'site_name' => 'Legacy Partner',
            'partner_material' => true,
        ]);

        $html = $this->actingAs($this->advertiser())
            ->get(route('advertiser.catalog', ['sponsored' => '1']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Legacy Sponsored', $html);
        $this->assertStringNotContainsString('Legacy Partner', $html);
        $this->assertStringContainsString('id="catalogTagFilter"', $html);
        $this->assertMatchesRegularExpression(
            '/id="catalogTagFilter"[\s\S]*?<option value="sponsored"[^>]*selected/s',
            $html
        );
    }

    public function test_tag_wins_over_sponsored_alias(): void
    {
        $publisher = $this->publisher();
        $this->site($publisher, [
            'site_name' => 'Alias Sponsored',
            'sponsored' => true,
        ]);
        $this->site($publisher, [
            'site_name' => 'Alias Partner',
            'partner_material' => true,
        ]);

        $html = $this->actingAs($this->advertiser())
            ->get(route('advertiser.catalog', [
                'tag' => 'partner_material',
                'sponsored' => '1',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Alias Partner', $html);
        $this->assertStringNotContainsString('Alias Sponsored', $html);
        $this->assertMatchesRegularExpression(
            '/data-more-filters-count[^>]*>\s*1\s*</',
            $html
        );
    }

    public function test_bulk_rail_follows_exclusive_tag_filter(): void
    {
        $publisher = $this->publisher();
        $this->site($publisher, [
            'site_name' => 'Bulk Prefer Deal',
            'as_you_prefer' => true,
            'bulk_discount_enabled' => true,
            'bulk_discount_percent' => 15,
        ]);
        $this->site($publisher, [
            'site_name' => 'Bulk Partner Deal',
            'partner_material' => true,
            'bulk_discount_enabled' => true,
            'bulk_discount_percent' => 12,
        ]);
        $this->site($publisher, [
            'site_name' => 'Bulk Leftover Deal',
            'sponsored' => true,
            'partner_material' => true,
            'as_you_prefer' => true,
            'bulk_discount_enabled' => true,
            'bulk_discount_percent' => 20,
        ]);

        $advertiser = $this->advertiser();

        $prefer = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog.bulk-deals', ['tag' => 'as_you_prefer']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Bulk Prefer Deal', $prefer);
        $this->assertStringNotContainsString('Bulk Partner Deal', $prefer);
        $this->assertStringNotContainsString('Bulk Leftover Deal', $prefer);

        $partner = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog.bulk-deals', ['tag' => 'partner_material']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Bulk Partner Deal', $partner);
        $this->assertStringNotContainsString('Bulk Prefer Deal', $partner);
        $this->assertStringNotContainsString('Bulk Leftover Deal', $partner);
    }

    public function test_live_results_and_recovery_keep_tag(): void
    {
        $publisher = $this->publisher();
        $this->site($publisher, [
            'site_name' => 'Live Partner',
            'partner_material' => true,
        ]);
        $this->site($publisher, [
            'site_name' => 'Live Untagged',
        ]);

        $html = $this->actingAs($this->advertiser())
            ->get(route('advertiser.catalog.results', ['tag' => 'partner_material']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Live Partner', $html);
        $this->assertStringNotContainsString('Live Untagged', $html);

        $kept = app(CatalogFilterStatus::class)->catalogQuery(
            Request::create('/advertiser/catalog', 'GET', [
                'tag' => 'none',
                'sponsored' => '1',
                'country' => 'de',
            ]),
            except: ['country', 'page']
        );
        $this->assertSame('none', $kept['tag'] ?? null);
        $this->assertArrayNotHasKey('sponsored', $kept);
        $this->assertArrayNotHasKey('country', $kept);
    }

    public function test_live_client_wires_tag_select(): void
    {
        $js = (string) file_get_contents(public_path('assets/js/catalog.js'));

        $this->assertStringContainsString("'tag'", $js);
        $this->assertStringContainsString("params: ['tag', 'sponsored']", $js);
        $this->assertStringContainsString("querySelector('[name=\"tag\"]')", $js);
        $this->assertStringContainsString("'tag', 'favorites_filter', 'blacklist_filter'", $js);
        $this->assertStringContainsString("out.set('tag', 'sponsored')", $js);
        $this->assertStringContainsString("'as you prefer': 'as_you_prefer'", $js);
        $this->assertStringContainsString("'partner article': 'partner_material'", $js);
        $this->assertStringContainsString("'tag', 'sponsored'", $js);
        $this->assertStringNotContainsString(
            "'tag', 'sponsored', 'favorites_filter', 'blacklist_filter', 'bulk_deals'",
            $js
        );
        $this->assertStringContainsString('tag= and legacy sponsored=1 are one More filter', $js);
    }
}
