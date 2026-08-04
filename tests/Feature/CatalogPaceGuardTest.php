<?php

namespace Tests\Feature;

use App\Models\InAppNotification;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteUrlReveal;
use App\Models\User;
use App\Services\Catalog\RevealPaceGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Pace, not quota.
 *
 * A daily allowance punishes the customer you want — an agency shortlisting
 * hundreds of sites — while barely inconveniencing a scraper who can register
 * again. So volume never blocks anyone, and what gets caught is a rate or a
 * rhythm no person produces.
 *
 * The tests worth having here are mostly about restraint: a busy human must sail
 * through, because a check that occasionally throttles real buyers is worse than
 * no check at all.
 */
class CatalogPaceGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function userWithRole(string $role): User
    {
        $roleModel = Role::firstOrCreate(['name' => $role]);
        $u = User::factory()->create(['email_verified_at' => now(), 'active_role_id' => $roleModel->id]);
        $u->roles()->attach($roleModel->id);

        return $u->fresh();
    }

    private function site(User $publisher, string $domain): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Listing '.uniqid(),
            'site_url' => 'https://'.$domain,
            'domain' => $domain,
            'da' => 40, 'dr' => 45, 'traffic' => 12000,
            'country' => 'us', 'language' => 'en',
            'countries' => ['us'], 'languages' => ['en'],
            'category' => 'marketing', 'price' => 150,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent', 'link_type' => 'dofollow',
            'description' => 'Pace test listing.',
            'verified' => true, 'active' => true,
        ]);
    }

    /**
     * Write history directly so a rate can be set up without waiting for it.
     *
     * @param  list<int>  $gapsSeconds
     */
    private function history(User $user, int $count, array $gapsSeconds): void
    {
        $publisher = $this->userWithRole('publisher');

        // Walk backwards from now so the history actually lands inside the
        // windows being tested rather than trailing off into last week.
        $total = 0;
        for ($i = 0; $i < $count; $i++) {
            $total += $gapsSeconds[$i % count($gapsSeconds)];
        }
        $at = now()->subSeconds($total);

        for ($i = 0; $i < $count; $i++) {
            $at = $at->copy()->addSeconds($gapsSeconds[$i % count($gapsSeconds)]);

            $reveal = SiteUrlReveal::create([
                'user_id' => $user->id,
                'site_id' => $this->site($publisher, "hist-{$user->id}-{$i}.example")->id,
                'source' => SiteUrlReveal::SOURCE_CATALOG,
            ]);

            // created_at is not fillable, so it has to be written after the fact
            // or every row lands at now() and any history looks instantaneous.
            SiteUrlReveal::where('id', $reveal->id)->update([
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }
    }

    private function guard(): RevealPaceGuard
    {
        return app(RevealPaceGuard::class);
    }

    // —— Restraint ————————————————————————————————————————————————

    public function test_a_busy_human_is_left_alone(): void
    {
        $user = $this->userWithRole('advertiser');

        // Fifty listings over an afternoon, at an uneven human rhythm.
        $this->history($user, 50, [9, 41, 17, 4, 63, 22]);

        $this->assertSame(RevealPaceGuard::OK, $this->guard()->assess($user)['state']);
    }

    public function test_a_quiet_browser_is_left_alone(): void
    {
        $user = $this->userWithRole('advertiser');
        $this->history($user, 5, [30, 90, 45]);

        $this->assertSame(RevealPaceGuard::OK, $this->guard()->assess($user)['state']);
    }

    // —— Catching a machine ————————————————————————————————————

    public function test_a_metronome_is_caught_even_at_a_modest_rate(): void
    {
        $user = $this->userWithRole('advertiser');

        // One every two seconds, exactly. People do not do this.
        $this->history($user, 20, [2]);

        $verdict = $this->guard()->assess($user);

        $this->assertSame(RevealPaceGuard::SLOW, $verdict['state']);
        $this->assertSame('even_timing', $verdict['reason']);
    }

    public function test_a_sustained_sprint_pauses_new_addresses(): void
    {
        config(['catalog.url_reveal.pace.freeze_after' => 40, 'catalog.url_reveal.pace.freeze_window_minutes' => 30]);

        $user = $this->userWithRole('advertiser');
        $this->history($user, 45, [3, 11, 2, 7]);

        $this->assertSame(RevealPaceGuard::FROZEN, $this->guard()->assess($user)['state']);
    }

    public function test_slowing_down_is_a_wait_not_a_refusal(): void
    {
        config(['catalog.url_reveal.pace.slow_after' => 10, 'catalog.url_reveal.pace.slow_window_minutes' => 5]);

        $user = $this->userWithRole('advertiser');
        $this->history($user, 15, [4, 19, 6, 31]);

        $verdict = $this->guard()->assess($user);

        $this->assertSame(RevealPaceGuard::SLOW, $verdict['state']);
        // The client waits and asks again; nobody is turned away.
        $this->assertGreaterThan(0, $verdict['retry_after']);
    }

    // —— Watch-only mode ————————————————————————————————————————

    public function test_nothing_is_restricted_while_calibrating(): void
    {
        config([
            'catalog.url_reveal.pace.enforce' => false,
            'catalog.url_reveal.pace.freeze_after' => 5,
        ]);

        $user = $this->userWithRole('advertiser');
        $this->history($user, 30, [1]);

        // Detects and reports, restricts nobody — how this should run until the
        // thresholds come from real data rather than a guess.
        $this->assertSame(RevealPaceGuard::OK, $this->guard()->assess($user)['state']);
    }

    // —— The exemption ————————————————————————————————————————

    public function test_a_trusted_account_is_never_touched(): void
    {
        config(['catalog.url_reveal.pace.freeze_after' => 5]);

        $user = $this->userWithRole('advertiser');
        $user->forceFill(['catalog_reveal_exempt' => true])->save();
        $this->history($user->fresh(), 30, [1]);

        $this->assertSame(RevealPaceGuard::OK, $this->guard()->assess($user->fresh())['state']);
    }

    public function test_an_admin_can_grant_and_take_back_the_exemption(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');

        $this->actingAs($admin)
            ->post(route('admin.catalog-activity.exempt', $advertiser->id))
            ->assertRedirect();
        $this->assertTrue((bool) $advertiser->fresh()->catalog_reveal_exempt);

        $this->actingAs($admin)
            ->post(route('admin.catalog-activity.exempt', $advertiser->id))
            ->assertRedirect();
        $this->assertFalse((bool) $advertiser->fresh()->catalog_reveal_exempt);
    }

    // —— Telling someone ————————————————————————————————————————

    public function test_unusual_volume_reaches_an_admin_without_touching_the_user(): void
    {
        config(['catalog.url_reveal.pace.review_after' => 20, 'catalog.url_reveal.pace.review_window_hours' => 24]);

        $this->userWithRole('admin');
        $user = $this->userWithRole('advertiser');
        $this->history($user, 25, [40, 95, 22, 61]);

        $verdict = $this->guard()->assess($user);

        $this->assertSame(RevealPaceGuard::OK, $verdict['state'], 'Volume alone must never restrict.');

        $bell = InAppNotification::where('audience', InAppNotification::AUDIENCE_ADMIN)->latest('id')->first();
        $this->assertNotNull($bell, 'No admin was told about unusual volume.');
        $this->assertStringContainsString('Nothing has been restricted', (string) $bell->message);
    }

    public function test_the_admin_screen_ranks_by_activity_and_shows_the_ratio(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $this->history($advertiser, 12, [30, 70]);

        $this->actingAs($admin)
            ->get(route('admin.catalog-activity'))
            ->assertOk()
            ->assertSee($advertiser->email)
            ->assertSee('Per order');
    }
}
