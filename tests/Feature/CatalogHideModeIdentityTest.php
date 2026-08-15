<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\SiteUrlReveal;
use App\Models\User;
use App\Services\Catalog\SiteUrlVisibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CatalogHideModeIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function advertiser(array $attrs = []): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ], $attrs));
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function site(string $domain, string $name = 'Secret Brand Blog'): Site
    {
        $pubRole = Role::firstOrCreate(['name' => 'publisher']);
        $publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $pubRole->id,
        ]);
        $publisher->roles()->attach($pubRole->id);

        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => $name,
            'site_url' => 'https://'.$domain.'/blog/post',
            'domain' => $domain,
            'da' => 40,
            'dr' => 45,
            'traffic' => 12000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 150,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Hide mode identity test.',
            'verified' => true,
            'active' => true,
        ]);
    }

    public function test_hide_mode_catalog_masks_name_and_url_without_leaking_plaintext(): void
    {
        $site = $this->site('hidden-brand.example', 'Hidden Brand Media');
        $user = $this->advertiser([
            'catalog_copy_strike_count' => 2,
            'catalog_hide_until' => now()->addHours(24),
        ]);

        $html = $this->actingAs($user)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('We’ve temporarily hidden listing names and website addresses', $html);
        $this->assertStringContainsString('browse, compare metrics, and place orders as normal', $html);
        $this->assertStringContainsString('support@seolinkbuildings.com', $html);
        $this->assertStringContainsString('inCatalogHideMode: true', $html);
        $this->assertStringNotContainsString('Hidden Brand Media', $html);
        $this->assertStringNotContainsString('hidden-brand.example', $html);
        $this->assertStringContainsString('Show site name and URL', $html);

        $visibility = app(SiteUrlVisibility::class);
        $maskedName = $visibility->maskName('Hidden Brand Media');
        $this->assertStringContainsString($maskedName, $html);
    }

    public function test_hide_mode_does_not_print_a_url_buried_in_the_description(): void
    {
        $site = $this->site('desc-leak.example', 'Desc Leak Brand');
        $site->update([
            'description' => 'Read more at https://desc-leak.example/about and hire us.',
        ]);
        $user = $this->advertiser([
            'catalog_copy_strike_count' => 2,
            'catalog_hide_until' => now()->addHours(24),
        ]);

        $html = $this->actingAs($user)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('desc-leak.example', $html);
        $this->assertStringNotContainsString('Desc Leak Brand', $html);
        $this->assertStringContainsString('then the description appears', $html);
    }

    public function test_outside_hide_mode_site_name_stays_visible(): void
    {
        $site = $this->site('open-brand.example', 'Open Brand Media');
        $user = $this->advertiser();

        $html = $this->actingAs($user)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Open Brand Media', $html);
        $this->assertStringNotContainsString('We’ve temporarily hidden listing names and website addresses', $html);
        $this->assertStringContainsString((string) $site->id, $html);
    }

    public function test_eye_reveal_returns_name_and_rooted_url_together(): void
    {
        $site = $this->site('reveal-both.example', 'Reveal Both Brand');
        $user = $this->advertiser([
            'catalog_copy_strike_count' => 2,
            'catalog_hide_until' => now()->addDay(),
        ]);

        $this->actingAs($user)
            ->postJson(route('advertiser.catalog.reveal-url', ['site' => $site->id]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('name', 'Reveal Both Brand')
            ->assertJsonPath('url', 'reveal-both.example')
            ->assertJsonPath('rooted_url', 'https://reveal-both.example');

        $html = $this->actingAs($user)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Reveal Both Brand', $html);
        $this->assertStringContainsString('https://reveal-both.example', $html);
    }

    public function test_conceal_in_hide_mode_returns_masked_name_and_url(): void
    {
        $site = $this->site('conceal-both.example', 'Conceal Both Brand');
        $user = $this->advertiser([
            'catalog_copy_strike_count' => 2,
            'catalog_hide_until' => now()->addDay(),
        ]);

        app(SiteUrlVisibility::class)->reveal($user, $site);

        $maskedName = app(SiteUrlVisibility::class)->maskName('Conceal Both Brand');

        $this->actingAs($user)
            ->postJson(route('advertiser.catalog.hide-url', ['site' => $site->id]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('masked_name', $maskedName)
            ->assertJsonStructure(['masked', 'masked_rooted', 'masked_name']);
    }

    public function test_name_for_masks_only_in_hide_mode(): void
    {
        $site = $this->site('policy.example', 'Policy Name Co');
        $visibility = app(SiteUrlVisibility::class);

        $normal = $this->advertiser();
        $this->assertSame('Policy Name Co', $visibility->nameFor($normal, $site));

        $hidden = $this->advertiser([
            'catalog_copy_strike_count' => 2,
            'catalog_hide_until' => now()->addDay(),
        ]);
        $this->assertSame($visibility->maskName('Policy Name Co'), $visibility->nameFor($hidden, $site));

        $visibility->reveal($hidden, $site);
        $this->assertSame('Policy Name Co', $visibility->nameFor($hidden->fresh(), $site));
    }

    public function test_reveal_and_conceal_ok_when_concealed_at_is_unparseable(): void
    {
        $site = $this->site('leftover-conceal.example', 'Leftover Conceal Brand');
        $user = $this->advertiser([
            'catalog_copy_strike_count' => 2,
            'catalog_hide_until' => now()->addDay(),
        ]);

        $row = SiteUrlReveal::create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'source' => SiteUrlReveal::SOURCE_CATALOG,
            'concealed_at' => now(),
        ]);
        DB::table('site_url_reveals')->where('id', $row->id)->update([
            'concealed_at' => 'not-a-date',
        ]);

        $visibility = app(SiteUrlVisibility::class);
        $this->assertSame('leftover-conceal.example', $visibility->reveal($user, $site));
        $this->assertNull($row->fresh()->concealed_at);

        $visibility->conceal($user, $site);
        $this->assertNotNull($row->fresh()->concealed_at);

        $html = $this->actingAs($user)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertDontSee('Something went wrong')
            ->getContent();

        $this->assertStringNotContainsString('Leftover Conceal Brand', $html);
    }

    public function test_catalog_ok_when_hide_until_is_unparseable(): void
    {
        $site = $this->site('leftover-hide.example', 'Leftover Hide Brand');
        $user = $this->advertiser([
            'catalog_copy_strike_count' => 2,
            'catalog_hide_until' => now()->addDay(),
        ]);
        DB::table('users')->where('id', $user->id)->update([
            'catalog_hide_until' => 'not-a-date',
        ]);

        $fresh = $user->fresh();
        $this->assertFalse($fresh->inCatalogHideMode());
        $this->assertNull($fresh->catalog_hide_until);

        $html = $this->actingAs($fresh)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertDontSee('Something went wrong')
            ->getContent();

        $this->assertStringContainsString('Leftover Hide Brand', $html);
        $this->assertStringContainsString('leftover-hide.example', $html);
        $this->assertStringNotContainsString('We’ve temporarily hidden listing names and website addresses', $html);
        $this->assertStringContainsString('inCatalogHideMode: false', $html);
        $this->assertStringContainsString((string) $site->id, $html);
    }

    public function test_copy_track_js_reloads_after_hide_mode_so_names_do_not_linger(): void
    {
        $js = file_get_contents(public_path('assets/js/catalog.js'));

        $this->assertStringContainsString("data.status === 'hide_mode'", $js);
        $this->assertStringContainsString('window.location.reload()', $js);
        $this->assertStringContainsString('.catalog-site-details', $js);
        $this->assertStringContainsString('swal-claim-url', $js);
        $this->assertStringContainsString('We cannot fill it in while the catalog is hiding names', $js);
    }
}
