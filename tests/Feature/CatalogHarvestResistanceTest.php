<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteUrlReveal;
use App\Models\User;
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

    // —— Search must not confirm a guess ————————————————————————

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

    // —— The page itself must be worthless to a scraper ————————

    public function test_the_open_link_does_not_carry_the_domain(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $this->site($publisher, 'never-in-the-html.example');

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        // The row offers a way to inspect the site, and still gives a scraper
        // nothing: the link points at us, not at the publisher.
        $this->assertStringNotContainsString('never-in-the-html.example', $html);
        $this->assertStringContainsString('/advertiser/go/', $html);
    }

    public function test_clicking_through_sends_them_to_the_site_and_records_it(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->site($publisher, 'visited-once.example');

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog.visit', $site->id))
            ->assertRedirect('https://visited-once.example');

        // Arriving on the site discloses the domain just as surely as reading
        // it, so it belongs in the same audit trail and the same pace maths.
        $this->assertDatabaseHas('site_url_reveals', [
            'user_id' => $advertiser->id,
            'site_id' => $site->id,
            'source' => SiteUrlReveal::SOURCE_VISIT,
        ]);
    }

    public function test_the_redirect_is_closed_to_guests(): void
    {
        $publisher = $this->userWithRole('publisher');
        $site = $this->site($publisher, 'no-guest-visits.example');

        $this->get(route('advertiser.catalog.visit', $site->id))
            ->assertRedirect(route('login'));
    }

    public function test_clicking_through_is_not_a_way_round_a_pause(): void
    {
        config([
            'catalog.url_reveal.pace.enforce' => true,
            'catalog.url_reveal.pace.freeze_after' => 3,
            'catalog.url_reveal.pace.freeze_window_minutes' => 30,
        ]);

        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');

        foreach (['p1.example', 'p2.example', 'p3.example'] as $domain) {
            $site = $this->site($publisher, $domain);
            $this->actingAs($advertiser)->postJson(route('advertiser.catalog.reveal-url', $site->id));
        }

        $blocked = $this->site($publisher, 'should-not-open.example');

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog.visit', $blocked->id))
            ->assertRedirect(route('advertiser.catalog'));

        $this->assertDatabaseMissing('site_url_reveals', ['site_id' => $blocked->id]);
    }
}
