<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteUrlReveal;
use App\Models\User;
use App\Services\Catalog\SiteUrlVisibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The one thing masking genuinely buys is that a competitor cannot walk off
 * with the inventory list. Everything here is written from that attacker's
 * point of view: not "does the eye icon work" but "how would I get all of it,
 * and what stops me".
 */
class CatalogHarvestResistanceTest extends TestCase
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
            'description' => 'Inventory that should not be harvestable.',
            'verified' => true, 'active' => true,
        ]);
    }

    /** @return list<Site> */
    private function inventory(int $count, string $prefix = 'inv'): array
    {
        $publisher = $this->userWithRole('publisher');
        $sites = [];

        for ($i = 1; $i <= $count; $i++) {
            $sites[] = $this->site($publisher, "{$prefix}-{$i}.example");
        }

        return $sites;
    }

    private function fund(User $user): void
    {
        DepositRequest::create([
            'user_id' => $user->id,
            'amount' => 50,
            'status' => 'approved',
            'payment_method' => 'bank_transfer',
            'reference_code' => 'REF-'.uniqid(),
        ]);
    }

    // —— Attack 1: use search as a confirmation oracle ————————————

    public function test_search_cannot_be_used_to_confirm_a_hidden_domain(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $this->site($publisher, 'guessable-domain.example');

        // The mask shows the first characters, so an attacker can guess the rest
        // and ask search whether the guess was right — for free, forever, with no
        // reveal recorded. That turns the mask into a puzzle with a hint line.
        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', ['search' => 'guessable-domain']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('gues***.example', $html);
        $this->assertStringContainsString('No ', $html);
    }

    public function test_search_still_works_on_everything_a_buyer_would_type(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->site($publisher, 'findable-by-name.example');
        $site->update(['site_name' => 'Northern Marketing Weekly']);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', ['search' => 'Northern Marketing']))
            ->assertOk()
            ->assertSee('find***.example');
    }

    public function test_search_matches_a_domain_the_advertiser_has_already_earned(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->site($publisher, 'already-mine.example');
        SiteUrlReveal::create(['user_id' => $advertiser->id, 'site_id' => $site->id]);

        // Once they hold the domain, searching for it is ordinary navigation.
        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', ['search' => 'already-mine']))
            ->assertOk()
            ->assertSee('already-mine.example');
    }

    // —— Attack 2: script a basket instead of clicking reveals ————

    public function test_a_scripted_basket_cannot_stand_in_for_reveals(): void
    {
        config([
            'catalog.url_reveal.daily_allowance_new' => 5,
            'catalog.url_reveal.cart_add_free_per_day' => 10,
        ]);

        $advertiser = $this->userWithRole('advertiser');
        $sites = $this->inventory(40, 'basket');

        $accepted = 0;
        foreach ($sites as $site) {
            $status = $this->actingAs($advertiser)
                ->postJson(route('advertiser.cart.add'), ['id' => $site->id])
                ->status();

            if ($status === 200) {
                $accepted++;
            }
        }

        // A basket is a purchase signal, not a download button. Real baskets are
        // small; forty distinct sites in one day is a script.
        $this->assertLessThan(
            40,
            $accepted,
            'Every cart add succeeded, so the basket is still a free route to the whole inventory.'
        );
    }

    public function test_a_normal_sized_basket_is_never_refused(): void
    {
        config([
            'catalog.url_reveal.daily_allowance_new' => 1,
            'catalog.url_reveal.cart_add_free_per_day' => 10,
        ]);

        $advertiser = $this->userWithRole('advertiser');
        $sites = $this->inventory(6, 'realbasket');

        // Allowance is spent on something else entirely.
        $spender = $this->inventory(1, 'spent')[0];
        $this->actingAs($advertiser)->postJson(route('advertiser.catalog.reveal-url', $spender->id));

        foreach ($sites as $site) {
            $this->actingAs($advertiser)
                ->postJson(route('advertiser.cart.add'), ['id' => $site->id])
                ->assertOk();
        }

        // Refusing a genuine purchase costs more than any scraping it enables.
        $this->assertSame(6, count($sites));
    }

    // —— Attack 3: buy your way to an unlimited cap ————————————

    public function test_a_small_deposit_does_not_unlock_the_whole_catalog(): void
    {
        config(['catalog.url_reveal.daily_allowance_funded' => 200]);

        $advertiser = $this->userWithRole('advertiser');
        $this->fund($advertiser);

        $remaining = app(SiteUrlVisibility::class)->remainingAllowance($advertiser->fresh());

        // Generous for any real shopping session, but a competitor should not be
        // able to buy the inventory list for the price of one deposit.
        $this->assertNotNull(
            $remaining,
            'A funded account has no ceiling at all, so €50 buys the entire inventory.'
        );
        $this->assertLessThanOrEqual(200, $remaining);
    }

    public function test_a_funded_buyer_still_has_far_more_than_they_need(): void
    {
        config(['catalog.url_reveal.daily_allowance_funded' => 200]);

        $advertiser = $this->userWithRole('advertiser');
        $this->fund($advertiser);

        $remaining = app(SiteUrlVisibility::class)->remainingAllowance($advertiser->fresh());

        // The cap exists to bound a script, not to be met by a person.
        $this->assertGreaterThanOrEqual(100, $remaining);
    }

    public function test_the_shipped_defaults_are_the_ones_that_matter(): void
    {
        // The tests above configure their own numbers, which proves the
        // mechanism but says nothing about what actually ships. These are the
        // values a real install runs on.
        $funded = (int) config('catalog.url_reveal.daily_allowance_funded');
        $ceiling = (int) config('catalog.url_reveal.burst_ceiling');
        $cartFree = (int) config('catalog.url_reveal.cart_add_free_per_day');

        $this->assertGreaterThan(0, $funded, 'Funded accounts ship uncapped, so one deposit buys the inventory.');
        $this->assertGreaterThan(0, $ceiling, 'No burst ceiling ships, so a script is only ever reported.');
        $this->assertGreaterThan(0, $cartFree, 'No free basket tier ships, so ordinary buyers would be metered.');

        // Generous enough that a person never meets them.
        $this->assertGreaterThanOrEqual(100, $funded);
        $this->assertGreaterThanOrEqual(10, $cartFree);
    }

    // —— Attack 4: outrun the alert ————————————————————————————

    public function test_a_burst_is_stopped_rather_than_merely_reported(): void
    {
        config([
            'catalog.url_reveal.daily_allowance_new' => 0,
            'catalog.url_reveal.daily_allowance_funded' => 0,
            'catalog.url_reveal.anomaly_threshold' => 3,
            'catalog.url_reveal.anomaly_window_minutes' => 60,
            'catalog.url_reveal.burst_ceiling' => 5,
        ]);

        $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $this->fund($advertiser);
        $sites = $this->inventory(12, 'burst');

        $served = 0;
        foreach ($sites as $site) {
            if ($this->actingAs($advertiser)
                ->postJson(route('advertiser.catalog.reveal-url', $site->id))
                ->status() === 200) {
                $served++;
            }
        }

        // A notification alone means the inventory is gone before anyone reads
        // it. Past the ceiling the account has to stop.
        $this->assertLessThan(
            12,
            $served,
            'The burst ran to completion; the alert only told an admin afterwards.'
        );
    }
}
