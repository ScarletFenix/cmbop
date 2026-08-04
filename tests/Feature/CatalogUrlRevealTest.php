<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteUrlReveal;
use App\Models\User;
use App\Services\Catalog\SiteUrlVisibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Publisher domains are metered rather than hidden.
 *
 * Anyone allowed to evaluate a site before buying will end up knowing its
 * domain, so the goal is not secrecy — it is that the whole inventory cannot be
 * harvested in an afternoon, and that when a publisher reports being approached
 * directly there is a record of who could have known.
 *
 * Browsing and opening addresses are unlimited — an agency may legitimately work
 * through hundreds — so what most of this file checks is that the real value
 * never reaches the browser unasked, and that a disclosure is always recorded.
 */
class CatalogUrlRevealTest extends TestCase
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

    private function site(?User $publisher = null, string $domain = 'secret-inventory.example'): Site
    {
        $publisher ??= $this->userWithRole('publisher');

        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Listing '.uniqid(),
            'site_url' => 'https://'.$domain,
            'domain' => $domain,
            'example_url' => 'https://'.$domain.'/a-sample-guest-post',
            'da' => 40, 'dr' => 45, 'traffic' => 12000,
            'country' => 'us', 'language' => 'en',
            'countries' => ['us'], 'languages' => ['en'],
            'category' => 'marketing', 'price' => 150,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent', 'link_type' => 'dofollow',
            'description' => 'A listing used to test domain visibility.',
            'verified' => true, 'active' => true,
        ]);
    }

    private function visibility(): SiteUrlVisibility
    {
        return app(SiteUrlVisibility::class);
    }

    // —— The domain must not be in the page ————————————————————————

    public function test_the_catalog_never_ships_the_real_domain(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $this->site(domain: 'secret-inventory.example');

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        // Not in a hidden span, an href, a data attribute, or anywhere else —
        // a mask that ships the answer beside it protects nothing.
        $this->assertStringNotContainsString('secret-inventory.example', $html);
        $this->assertStringContainsString('***.example', $html);
    }

    public function test_the_sample_article_does_not_give_the_domain_away(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $this->site(domain: 'sample-leak.example');

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        // The sample post lives on the same domain, so printing its URL would
        // hand over the address the row is masking.
        $this->assertStringNotContainsString('a-sample-guest-post', $html);
    }

    public function test_a_revealed_domain_is_rendered_in_full_next_time(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->site(domain: 'already-seen.example');

        SiteUrlReveal::create(['user_id' => $advertiser->id, 'site_id' => $site->id]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertSee('already-seen.example');
    }

    // —— Revealing ——————————————————————————————————————————————

    public function test_asking_returns_the_domain_and_records_it(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->site(domain: 'revealed-once.example');

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.catalog.reveal-url', $site->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('url', 'revealed-once.example');

        $this->assertDatabaseHas('site_url_reveals', [
            'user_id' => $advertiser->id,
            'site_id' => $site->id,
            'source' => SiteUrlReveal::SOURCE_CATALOG,
        ]);
    }

    public function test_asking_twice_is_one_disclosure(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->site();

        $this->actingAs($advertiser)->postJson(route('advertiser.catalog.reveal-url', $site->id))->assertOk();
        $this->actingAs($advertiser)->postJson(route('advertiser.catalog.reveal-url', $site->id))->assertOk();

        // Re-opening something they have already seen must not cost a second
        // allowance, or the sticky reveal is a trap rather than a convenience.
        $this->assertSame(1, SiteUrlReveal::where('user_id', $advertiser->id)->count());
    }

    public function test_a_guest_cannot_ask(): void
    {
        $site = $this->site();

        $this->postJson(route('advertiser.catalog.reveal-url', $site->id))
            ->assertStatus(401);
    }

    public function test_volume_alone_never_blocks_anyone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');

        // An agency planning a campaign legitimately works through hundreds of
        // listings across a day. There is no daily ceiling to hit — the checks
        // are about pace, so a human rhythm passes however much of it there is.
        // Deliberately uneven: a person pauses to read, gets distracted, comes
        // back. That irregularity is the whole difference between a buyer and a
        // loop, so a realistic test has to have it.
        $gaps = [7, 34, 12, 3, 58, 19, 5, 91, 26, 11, 44, 8];

        for ($i = 1; $i <= 300; $i++) {
            $site = $this->site($publisher, "volume-{$i}.example");

            $this->travel($gaps[$i % count($gaps)])->seconds();

            $this->actingAs($advertiser)
                ->postJson(route('advertiser.catalog.reveal-url', $site->id))
                ->assertOk()
                ->assertJsonPath('url', "volume-{$i}.example");
        }

        $this->assertSame(300, SiteUrlReveal::where('user_id', $advertiser->id)->count());
        $this->travelBack();
    }

    public function test_a_big_basket_is_never_refused(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');

        // Nothing should ever stand between someone and a purchase.
        for ($i = 1; $i <= 30; $i++) {
            $site = $this->site($publisher, "bigbasket-{$i}.example");

            $this->actingAs($advertiser)
                ->postJson(route('advertiser.cart.add'), ['id' => $site->id])
                ->assertOk();
        }
    }

    // —— Owned ————————————————————————————————————————————————————

    public function test_putting_a_site_in_the_cart_reveals_it(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->site(domain: 'in-my-cart.example');

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.cart.add'), ['id' => $site->id])
            ->assertOk();

        // You cannot check out against a masked domain.
        $this->assertDatabaseHas('site_url_reveals', [
            'user_id' => $advertiser->id,
            'site_id' => $site->id,
            'source' => SiteUrlReveal::SOURCE_CART,
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertSee('in-my-cart.example');
    }

    // —— Who else can see ————————————————————————————————————————

    public function test_a_publisher_always_sees_their_own_listing(): void
    {
        $publisher = $this->userWithRole('publisher');
        $site = $this->site($publisher, 'my-own-site.example');

        $this->assertTrue($this->visibility()->canSee($publisher, $site));
        $this->assertSame('my-own-site.example', $this->visibility()->hostFor($publisher, $site));
    }

    public function test_staff_are_not_the_audience_this_protects_against(): void
    {
        $admin = $this->userWithRole('admin');
        $site = $this->site(domain: 'staff-can-see.example');

        $this->assertSame('staff-can-see.example', $this->visibility()->hostFor($admin, $site));
    }

    public function test_a_guest_gets_nothing(): void
    {
        $site = $this->site(domain: 'no-guests.example');

        $this->assertFalse($this->visibility()->canSee(null, $site));
        $this->assertSame('no-g***.example', $this->visibility()->hostFor(null, $site));
    }

    // —— Masking itself ————————————————————————————————————————

    public function test_the_mask_keeps_the_shape_without_the_answer(): void
    {
        $vis = $this->visibility();

        $this->assertSame('exam***.com', $vis->mask('https://example.com'));
        $this->assertSame('exam***.com', $vis->mask('https://www.example.com/some/path'));
        $this->assertSame('blog***.uk', $vis->mask('http://blog.co.uk'));
        $this->assertSame('••••••', $vis->mask(''));
    }

    public function test_ordered_sites_stay_visible_after_checkout(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->site(domain: 'bought-this.example');

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-'.uniqid(),
            'reference_code' => 'REF-'.uniqid(),
            'subtotal' => 150, 'tax' => 0, 'total_amount' => 150,
            'payment_method' => 'wallet', 'payment_status' => 'paid',
            'status' => 'processing', 'paid_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 150, 'publisher_price' => 120,
            'content_link' => 'https://example.com/article.docx',
        ]);

        // Buying it is the strongest claim there is; the reveal is recorded at
        // cart time and the order pages have always shown the address.
        $this->visibility()->reveal($advertiser, $site, SiteUrlReveal::SOURCE_ORDER);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertSee('bought-this.example');
    }
}
