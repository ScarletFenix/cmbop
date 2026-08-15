<?php

namespace Tests\Feature;

use App\Mail\NewSitesDigest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserFavorite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The digest exists to bring buyers back to the catalog, so the interesting
 * behaviour is what it refuses to send: nothing to people who never bought,
 * nothing about sites they already own, and nothing at all when there is too
 * little to show — a digest listing one site is worse than no digest.
 */
class NewSitesDigestTest extends TestCase
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
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $roleModel->id,
        ]);
        $user->roles()->attach($roleModel->id);

        return $user->fresh();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function site(User $publisher, array $extra = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Digest Site '.uniqid(),
            'site_url' => 'https://digest-'.uniqid().'.example',
            'domain' => 'digest-'.uniqid().'.example',
            'da' => 40,
            'dr' => 50,
            'traffic' => 10000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 120,
            'turnaround_time' => '3days',
            'publication_time' => '5 days',
            'link_type' => 'dofollow',
            'description' => 'Digest test site',
            'verified' => true,
            'active' => true,
        ], $extra));
    }

    private function paidOrderFor(User $advertiser, Site $site): Order
    {
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-DIG-'.uniqid(),
            'reference_code' => 'REF-DIG-'.uniqid(),
            'subtotal' => 120,
            'tax' => 0,
            'total_amount' => 120,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'completed',
            'paid_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 120,
            'publisher_price' => 100,
            'content_link' => 'https://example.com/article.docx',
        ]);

        return $order->fresh('items');
    }

    private function stockCatalog(User $publisher, int $count = 4): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->site($publisher);
        }
    }

    public function test_a_paying_advertiser_receives_the_digest(): void
    {
        $publisher = $this->userWithRole('publisher');
        $advertiser = $this->userWithRole('advertiser');
        $this->paidOrderFor($advertiser, $this->site($publisher));
        $this->stockCatalog($publisher);

        $this->artisan('sites:send-new-sites-digest')->assertSuccessful();

        Mail::assertQueued(NewSitesDigest::class, fn ($mail) => $mail->hasTo($advertiser->email));
        $this->assertNotNull($advertiser->fresh()->new_sites_digest_sent_at);
    }

    public function test_someone_who_never_bought_anything_is_not_marketed_to(): void
    {
        $publisher = $this->userWithRole('publisher');
        $this->userWithRole('advertiser');
        $this->stockCatalog($publisher);

        $this->artisan('sites:send-new-sites-digest')->assertSuccessful();

        Mail::assertNotQueued(NewSitesDigest::class);
    }

    public function test_an_abandoned_unpaid_order_does_not_make_someone_a_customer(): void
    {
        $publisher = $this->userWithRole('publisher');
        $advertiser = $this->userWithRole('advertiser');
        $order = $this->paidOrderFor($advertiser, $this->site($publisher));
        $order->update(['payment_status' => 'pending', 'paid_at' => null]);
        $this->stockCatalog($publisher);

        $this->artisan('sites:send-new-sites-digest')->assertSuccessful();

        Mail::assertNotQueued(NewSitesDigest::class);
    }

    public function test_a_thin_catalog_produces_no_email_at_all(): void
    {
        $publisher = $this->userWithRole('publisher');
        $advertiser = $this->userWithRole('advertiser');
        $this->paidOrderFor($advertiser, $this->site($publisher));
        // Only two unseen sites against a minimum of three.
        $this->stockCatalog($publisher, 2);

        $this->artisan('sites:send-new-sites-digest')->assertSuccessful();

        Mail::assertNotQueued(NewSitesDigest::class);
        // The clock is untouched, so they are reconsidered on the next run
        // rather than skipped for another fifteen days.
        $this->assertNull($advertiser->fresh()->new_sites_digest_sent_at);
    }

    public function test_sites_the_advertiser_already_knows_are_left_out(): void
    {
        $publisher = $this->userWithRole('publisher');
        $advertiser = $this->userWithRole('advertiser');

        $bought = $this->site($publisher, ['site_name' => 'Already Bought']);
        $this->paidOrderFor($advertiser, $bought);

        $saved = $this->site($publisher, ['site_name' => 'Already Saved']);
        UserFavorite::create(['user_id' => $advertiser->id, 'site_id' => $saved->id]);

        $this->stockCatalog($publisher);

        $this->artisan('sites:send-new-sites-digest')->assertSuccessful();

        Mail::assertQueued(NewSitesDigest::class, function ($mail) {
            $names = $mail->rows->pluck('site.site_name');

            return ! $names->contains('Already Bought') && ! $names->contains('Already Saved');
        });
    }

    public function test_a_discounted_site_leads_and_shows_what_they_would_pay(): void
    {
        $publisher = $this->userWithRole('publisher');
        $advertiser = $this->userWithRole('advertiser');
        $this->paidOrderFor($advertiser, $this->site($publisher));
        $this->stockCatalog($publisher);

        $this->site($publisher, [
            'site_name' => 'On Offer',
            'price' => 200,
            'custom_discount_percent' => 25,
            'custom_discount_starts_at' => now()->subDay(),
            'custom_discount_ends_at' => now()->addDays(5),
        ]);

        $this->artisan('sites:send-new-sites-digest')->assertSuccessful();

        Mail::assertQueued(NewSitesDigest::class, function ($mail) {
            $first = $mail->rows->first();

            // Publisher €200 → advertiser list €226 (13% fee). Nominal 25% would
            // be €169.50, but the payout floor keeps pay at €200 → effective 11.5%.
            return $first['site']->site_name === 'On Offer'
                && (float) $first['discount'] === 11.5
                && (float) $first['price'] === 200.0
                && (float) $first['was'] === 226.0;
        });
    }

    public function test_ranking_prefers_effective_savings_over_nominal_percent(): void
    {
        $publisher = $this->userWithRole('publisher');
        $advertiser = $this->userWithRole('advertiser');
        $this->paidOrderFor($advertiser, $this->site($publisher));

        // High nominal, hard floor → ~11.5% effective on €113 list.
        $this->site($publisher, [
            'site_name' => 'Nominal Seventy',
            'price' => 100,
            'dr' => 10,
            'da' => 10,
            'traffic' => 1000,
            'custom_discount_percent' => 70,
            'custom_discount_starts_at' => now()->subDay(),
            'custom_discount_ends_at' => now()->addDays(5),
            'created_at' => now()->subDays(10),
        ]);

        // Lower nominal but higher effective after floor (~13% on €46 list).
        $this->site($publisher, [
            'site_name' => 'Effective Fourteen',
            'price' => 40,
            'dr' => 10,
            'da' => 10,
            'traffic' => 1000,
            'custom_discount_percent' => 14,
            'custom_discount_starts_at' => now()->subDay(),
            'custom_discount_ends_at' => now()->addDays(5),
            'created_at' => now()->subDays(10),
        ]);

        $this->site($publisher, [
            'site_name' => 'Filler A',
            'created_at' => now()->subDays(2),
        ]);
        $this->site($publisher, [
            'site_name' => 'Filler B',
            'created_at' => now()->subDays(2),
        ]);

        $this->artisan('sites:send-new-sites-digest')->assertSuccessful();

        Mail::assertQueued(NewSitesDigest::class, function ($mail) {
            $names = $mail->rows->pluck('site.site_name')->all();

            return ($names[0] ?? null) === 'Effective Fourteen'
                && in_array('Nominal Seventy', $names, true);
        });
    }

    public function test_an_expired_discount_is_not_advertised_as_live(): void
    {
        $publisher = $this->userWithRole('publisher');
        $advertiser = $this->userWithRole('advertiser');
        $this->paidOrderFor($advertiser, $this->site($publisher));
        $this->stockCatalog($publisher);

        $this->site($publisher, [
            'site_name' => 'Offer Ended',
            'price' => 200,
            'custom_discount_percent' => 40,
            'custom_discount_starts_at' => now()->subDays(10),
            'custom_discount_ends_at' => now()->subDay(),
        ]);

        $this->artisan('sites:send-new-sites-digest')->assertSuccessful();

        Mail::assertQueued(NewSitesDigest::class, function ($mail) {
            $row = $mail->rows->firstWhere('site.site_name', 'Offer Ended');

            // No live offer: show advertiser list (€200 + 13% fee), not publisher base.
            return $row === null || ($row['discount'] === null && (float) $row['price'] === 226.0);
        });
    }

    public function test_the_digest_waits_out_its_cycle_before_sending_again(): void
    {
        $publisher = $this->userWithRole('publisher');
        $advertiser = $this->userWithRole('advertiser');
        $this->paidOrderFor($advertiser, $this->site($publisher));
        $this->stockCatalog($publisher);

        $this->artisan('sites:send-new-sites-digest')->assertSuccessful();
        $this->artisan('sites:send-new-sites-digest')->assertSuccessful();

        $this->assertCount(1, Mail::queued(NewSitesDigest::class));
    }

    public function test_the_cycle_reopens_once_the_interval_has_passed(): void
    {
        $publisher = $this->userWithRole('publisher');
        $advertiser = $this->userWithRole('advertiser');
        $this->paidOrderFor($advertiser, $this->site($publisher));
        $this->stockCatalog($publisher);

        $advertiser->forceFill(['new_sites_digest_sent_at' => now()->subDays(16)])->save();

        $this->artisan('sites:send-new-sites-digest')->assertSuccessful();

        Mail::assertQueued(NewSitesDigest::class);
    }

    public function test_the_digest_never_exceeds_its_size_limit(): void
    {
        $publisher = $this->userWithRole('publisher');
        $advertiser = $this->userWithRole('advertiser');
        $this->paidOrderFor($advertiser, $this->site($publisher));
        $this->stockCatalog($publisher, 20);

        $this->artisan('sites:send-new-sites-digest')->assertSuccessful();

        Mail::assertQueued(NewSitesDigest::class, fn ($mail) => $mail->rows->count() === 6);
    }

    public function test_unverified_and_inactive_listings_stay_out_of_the_catalog_email(): void
    {
        $publisher = $this->userWithRole('publisher');
        $advertiser = $this->userWithRole('advertiser');
        $this->paidOrderFor($advertiser, $this->site($publisher));
        $this->stockCatalog($publisher);

        $this->site($publisher, ['site_name' => 'Not Verified', 'verified' => false]);
        $this->site($publisher, ['site_name' => 'Switched Off', 'active' => false]);

        $this->artisan('sites:send-new-sites-digest')->assertSuccessful();

        Mail::assertQueued(NewSitesDigest::class, function ($mail) {
            $names = $mail->rows->pluck('site.site_name');

            return ! $names->contains('Not Verified') && ! $names->contains('Switched Off');
        });
    }

    public function test_hide_mode_skips_the_digest_and_leaves_the_clock(): void
    {
        $publisher = $this->userWithRole('publisher');
        $advertiser = $this->userWithRole('advertiser');
        $this->paidOrderFor($advertiser, $this->site($publisher));
        $this->stockCatalog($publisher);

        $advertiser->forceFill([
            'catalog_copy_strike_count' => 2,
            'catalog_hide_until' => now()->addDay(),
        ])->save();

        $this->artisan('sites:send-new-sites-digest')->assertSuccessful();

        Mail::assertNotQueued(NewSitesDigest::class);
        $this->assertNull($advertiser->fresh()->new_sites_digest_sent_at);
    }
}
