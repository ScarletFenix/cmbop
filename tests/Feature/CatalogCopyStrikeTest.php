<?php

namespace Tests\Feature;

use App\Models\CatalogCopyEvent;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Catalog\CatalogCopyStrikeGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CatalogCopyStrikeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        config([
            'catalog.copy_strikes.threshold' => 5,
            'catalog.copy_strikes.window_seconds' => 120,
            'catalog.copy_strikes.hide_hours' => 24,
        ]);
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

    private function site(string $domain): Site
    {
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        $publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $publisher->roles()->attach($publisherRole->id);

        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Listing '.$domain,
            'site_url' => 'https://'.$domain,
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
            'description' => 'Copy strike test listing.',
            'verified' => true,
            'active' => true,
        ]);
    }

    public function test_ignores_non_domain_text(): void
    {
        $user = $this->advertiser();
        $guard = app(CatalogCopyStrikeGuard::class);

        $result = $guard->record($user, null, 'just some words');

        $this->assertSame(CatalogCopyStrikeGuard::STATUS_IGNORED, $result['status']);
        $this->assertSame(0, CatalogCopyEvent::count());
    }

    public function test_records_distinct_domain_copies_without_strike_below_threshold(): void
    {
        $user = $this->advertiser();
        $guard = app(CatalogCopyStrikeGuard::class);

        for ($i = 1; $i <= 4; $i++) {
            $site = $this->site("copy-{$i}.example");
            $result = $guard->record($user, $site->id, 'https://copy-'.$i.'.example');
            $this->assertSame(CatalogCopyStrikeGuard::STATUS_RECORDED, $result['status']);
        }

        $this->assertSame(0, (int) $user->fresh()->catalog_copy_strike_count);
        $this->assertSame(4, CatalogCopyEvent::where('user_id', $user->id)->count());
    }

    public function test_first_threshold_crossing_warns_and_keeps_visibility(): void
    {
        $user = $this->advertiser();
        $guard = app(CatalogCopyStrikeGuard::class);

        $last = null;
        for ($i = 1; $i <= 5; $i++) {
            $site = $this->site("warn-{$i}.example");
            $last = $guard->record($user, $site->id, 'warn-'.$i.'.example');
        }

        $this->assertSame(CatalogCopyStrikeGuard::STATUS_WARNING, $last['status']);
        $user = $user->fresh();
        $this->assertSame(1, (int) $user->catalog_copy_strike_count);
        $this->assertNotNull($user->catalog_copy_warned_at);
        $this->assertNull($user->catalog_hide_until);
        $this->assertFalse($user->inCatalogHideMode());
    }

    public function test_second_threshold_after_warning_sets_hide_mode_24h(): void
    {
        $user = $this->advertiser();
        $guard = app(CatalogCopyStrikeGuard::class);

        // Wave 1 → warning (and clears the window so same-second MySQL
        // timestamps cannot block strike 2).
        $last = null;
        for ($i = 1; $i <= 5; $i++) {
            $site = $this->site("warn-then-hide-a-{$i}.example");
            $last = $guard->record($user, $site->id, 'https://warn-then-hide-a-'.$i.'.example');
        }
        $this->assertSame(CatalogCopyStrikeGuard::STATUS_WARNING, $last['status']);
        $this->assertSame(0, CatalogCopyEvent::where('user_id', $user->id)->count());

        // Wave 2 in the same second → hide mode.
        for ($i = 1; $i <= 5; $i++) {
            $site = $this->site("warn-then-hide-b-{$i}.example");
            $last = $guard->record($user->fresh(), $site->id, 'https://warn-then-hide-b-'.$i.'.example/path');
        }

        $this->assertSame(CatalogCopyStrikeGuard::STATUS_HIDE_MODE, $last['status']);
        $user = $user->fresh();
        $this->assertSame(2, (int) $user->catalog_copy_strike_count);
        $this->assertTrue($user->inCatalogHideMode());
        $this->assertNotNull($user->catalog_hide_until);
        $this->assertTrue($user->catalog_hide_until->greaterThan(now()->addHours(23)));
        $this->assertTrue($user->catalog_hide_until->lessThanOrEqualTo(now()->addHours(24)->addMinute()));
        $this->assertStringContainsString('24 hours', $last['message']);
    }

    public function test_hide_mode_message_uses_configured_hours(): void
    {
        config(['catalog.copy_strikes.hide_hours' => 12, 'catalog.copy_strikes.threshold' => 2]);

        $user = $this->advertiser(['catalog_copy_strike_count' => 1]);
        $guard = app(CatalogCopyStrikeGuard::class);
        $last = null;
        for ($i = 1; $i <= 2; $i++) {
            $last = $guard->record($user->fresh(), $this->site("cfg-hide-{$i}.example")->id, "cfg-hide-{$i}.example");
        }

        $this->assertSame(CatalogCopyStrikeGuard::STATUS_HIDE_MODE, $last['status']);
        $this->assertStringContainsString('12 hours', $last['message']);
        $this->assertStringNotContainsString('24 hours', $last['message']);
    }

    public function test_warning_and_hide_can_fire_in_the_same_second(): void
    {
        config(['catalog.copy_strikes.threshold' => 3]);

        $user = $this->advertiser();
        $guard = app(CatalogCopyStrikeGuard::class);
        $last = null;

        for ($i = 1; $i <= 6; $i++) {
            $site = $this->site("same-sec-{$i}.example");
            $last = $guard->record($user->fresh(), $site->id, 'same-sec-'.$i.'.example');
        }

        $this->assertSame(CatalogCopyStrikeGuard::STATUS_HIDE_MODE, $last['status']);
        $this->assertTrue($user->fresh()->inCatalogHideMode());
    }

    public function test_same_site_copy_dedupes_inside_window(): void
    {
        $user = $this->advertiser();
        $site = $this->site('once.example');
        $guard = app(CatalogCopyStrikeGuard::class);

        $guard->record($user, $site->id, 'https://once.example');
        $guard->record($user, $site->id, 'https://once.example');
        $guard->record($user, $site->id, 'once.example');

        $this->assertSame(1, CatalogCopyEvent::where('user_id', $user->id)->count());
    }

    public function test_copy_track_endpoint_applies_warning(): void
    {
        $user = $this->advertiser();

        for ($i = 1; $i <= 5; $i++) {
            $site = $this->site("api-{$i}.example");
            $response = $this->actingAs($user)->postJson(route('advertiser.catalog.copy-track'), [
                'text' => 'https://api-'.$i.'.example',
                'site_id' => $site->id,
            ]);
            $response->assertOk()->assertJsonPath('success', true);
        }

        $response->assertJsonPath('status', CatalogCopyStrikeGuard::STATUS_WARNING);
        $this->assertSame(1, (int) $user->fresh()->catalog_copy_strike_count);
    }

    public function test_normalize_host_keeps_subdomain_strips_path(): void
    {
        $guard = app(CatalogCopyStrikeGuard::class);

        $this->assertSame('news.site.com', $guard->normalizeHost('https://news.site.com/blog/post?x=1'));
        $this->assertSame('example.com', $guard->normalizeHost('www.example.com'));
        $this->assertSame('', $guard->normalizeHost('not a host'));
    }

    public function test_copy_tracking_pauses_while_hide_mode_is_active(): void
    {
        $user = $this->advertiser([
            'catalog_copy_strike_count' => 2,
            'catalog_hide_until' => now()->addDay(),
        ]);
        $site = $this->site('already-hidden.example');
        $guard = app(CatalogCopyStrikeGuard::class);

        $result = $guard->record($user, $site->id, 'https://already-hidden.example');

        $this->assertSame(CatalogCopyStrikeGuard::STATUS_IGNORED, $result['status']);
        $this->assertSame(0, CatalogCopyEvent::where('user_id', $user->id)->count());
        $this->assertTrue($user->fresh()->inCatalogHideMode());
    }
}
