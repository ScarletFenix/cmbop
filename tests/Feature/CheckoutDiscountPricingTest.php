<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CartPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class CheckoutDiscountPricingTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

    private User $advertiser;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        config(['content_moderation.enabled' => false]);
        Mail::fake();
        Role::firstOrCreate(['name' => 'admin']);

        $advertiserRole = Role::firstOrCreate(['name' => 'advertiser']);
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);

        $this->advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $this->advertiser->roles()->attach($advertiserRole->id);

        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->attach($publisherRole->id);

        Wallet::create([
            'user_id' => $this->advertiser->id,
            'role_id' => $advertiserRole->id,
            'balance' => 2000,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);
    }

    private function makeSite(string $slug, float $price, array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Discount '.$slug,
            'site_url' => 'https://'.$slug.'.example',
            'domain' => $slug.'.example',
            'da' => 40,
            'dr' => 50,
            'traffic' => 8000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => $price,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Discount checkout listing.',
            'verified' => true,
            'active' => true,
        ], $overrides));
    }

    /**
     * @param  array<int, array<string, mixed>>  $cart
     */
    private function checkoutPage(array $cart): string
    {
        return $this->actingAs($this->advertiser)
            ->withSession(['cart' => $cart])
            ->get(route('advertiser.checkout'))
            ->assertOk()
            ->getContent();
    }

    /**
     * @param  array<int, array<string, mixed>>  $cart
     * @return array<string, mixed>
     */
    private function payWallet(array $cart, string $ref): array
    {
        return $this->actingAs($this->advertiser)
            ->withSession([
                'cart' => $cart,
                'checkout_schedule' => ['mode' => 'immediate', 'timezone' => 'UTC'],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => $ref,
                'publication_mode' => 'immediate',
            ])
            ->assertOk()
            ->assertJson(['success' => true])
            ->json();
    }

    public function test_ten_percent_sale_on_one_hundred_shows_one_thirteen_then_one_oh_one_seventy(): void
    {
        $site = $this->makeSite('tenoff', 100, [
            'custom_discount_percent' => 10,
            'custom_discount_starts_at' => now()->subDay(),
            'custom_discount_ends_at' => now()->addDays(5),
        ]);
        $sub = $this->createApprovedSubmission($this->advertiser, null, 0, 'ten off', 'https://example.com/ten');
        $expected = app(CartPricingService::class)->priceForAdvertiser($site);
        $this->assertSame(113.0, $expected['list_total']);
        $this->assertSame(101.7, $expected['total']);
        $this->assertSame(10.0, $expected['discount_percent']);

        $cart = [[
            'id' => $site->id,
            'name' => $site->site_name,
            'quantity' => 1,
            'content_submission_id' => $sub->id,
        ]];

        $html = $this->checkoutPage($cart);
        $this->assertStringContainsString('€113.00', $html);
        $this->assertStringContainsString('€101.70', $html);
        $this->assertStringContainsString('You save €11.30', $html);
        $this->assertStringContainsString('text-decoration-line-through', $html);

        $this->payWallet($cart, 'TEN10');

        $item = OrderItem::query()->whereHas('order', fn ($q) => $q->where('reference_code', 'TEN10'))->firstOrFail();
        $this->assertEquals(101.7, (float) $item->price);
        $this->assertEquals(100.0, (float) $item->publisher_price);
        $this->assertEquals(1.7, (float) $item->platform_fee_amount);
    }

    public function test_sale_discount_shows_on_checkout_and_charges_floored_price(): void
    {
        $site = $this->makeSite('sale', 90, [
            'custom_discount_percent' => 25,
            'custom_discount_starts_at' => now()->subDay(),
            'custom_discount_ends_at' => now()->addDays(5),
        ]);
        $sub = $this->createApprovedSubmission($this->advertiser, null, 0, 'sale tools', 'https://example.com/sale');
        $expected = app(CartPricingService::class)->priceForAdvertiser($site);
        $this->assertSame(103.5, $expected['base']);
        $this->assertSame(90.0, $expected['total']);

        $cart = [[
            'id' => $site->id,
            'name' => $site->site_name,
            'quantity' => 1,
            'content_submission_id' => $sub->id,
        ]];

        $html = $this->checkoutPage($cart);
        $this->assertStringContainsString('Site offer', $html);
        $this->assertStringContainsString('€103.50', $html);
        $this->assertStringContainsString('€90.00', $html);
        $this->assertStringContainsString('You save €13.50', $html);

        $this->payWallet($cart, 'SALE1');

        $item = OrderItem::query()->whereHas('order', fn ($q) => $q->where('reference_code', 'SALE1'))->firstOrFail();
        $this->assertEquals(90.0, (float) $item->price);
        $this->assertEquals(90.0, (float) $item->publisher_price);
        $this->assertEquals(0.0, (float) $item->platform_fee_amount);
        $this->assertEquals(90.0, (float) Order::where('reference_code', 'SALE1')->value('total_amount'));
    }

    public function test_bulk_discount_applies_to_checkout_page_and_wallet_orders(): void
    {
        $site = $this->makeSite('bulk', 100, [
            'bulk_discount_enabled' => true,
            'bulk_discount_percent' => 10,
        ]);
        $slots = [
            $this->createApprovedSubmission($this->advertiser, null, 0, 'bulk one', 'https://example.com/b1'),
            $this->createApprovedSubmission($this->advertiser, null, 1, 'bulk two', 'https://example.com/b2'),
            $this->createApprovedSubmission($this->advertiser, null, 2, 'bulk three', 'https://example.com/b3'),
        ];
        $expected = app(CartPricingService::class)->priceForAdvertiser($site, null, 3);
        $this->assertSame(113.0, $expected['list_total']);
        $this->assertSame(10.0, $expected['discount_percent']);
        $this->assertSame(101.7, $expected['total']);

        $cart = [[
            'id' => $site->id,
            'name' => $site->site_name,
            'quantity' => 3,
            'bulk_pack' => true,
            'content_submission_id' => $slots[0]->id,
            'content_submission_ids' => [$slots[0]->id, $slots[1]->id, $slots[2]->id],
        ]];

        $html = $this->checkoutPage($cart);
        $this->assertStringContainsString('Bulk deal', $html);
        $this->assertStringContainsString('€113.00', $html);
        $this->assertStringContainsString('€101.70', $html);
        $this->assertStringContainsString('You save €33.90', $html);

        $this->payWallet($cart, 'BULK1');

        $items = OrderItem::query()
            ->whereHas('order', fn ($q) => $q->where('reference_code', 'BULK1'))
            ->get();
        $this->assertCount(3, $items);
        foreach ($items as $item) {
            $this->assertEquals(101.7, (float) $item->price);
            $this->assertEquals(100.0, (float) $item->publisher_price);
            $this->assertEquals(1.7, (float) $item->platform_fee_amount);
        }
        $this->assertEquals(305.1, round((float) Order::where('reference_code', 'BULK1')->sum('total_amount'), 2));
    }

    public function test_better_of_uses_sale_not_stacked_bulk_at_checkout(): void
    {
        $site = $this->makeSite('better', 100, [
            'bulk_discount_enabled' => true,
            'bulk_discount_percent' => 10,
            'custom_discount_percent' => 20,
            'custom_discount_starts_at' => now()->subDay(),
            'custom_discount_ends_at' => now()->addDays(5),
        ]);
        $slots = [
            $this->createApprovedSubmission($this->advertiser, null, 0, 'better one', 'https://example.com/x1'),
            $this->createApprovedSubmission($this->advertiser, null, 1, 'better two', 'https://example.com/x2'),
            $this->createApprovedSubmission($this->advertiser, null, 2, 'better three', 'https://example.com/x3'),
        ];
        $expected = app(CartPricingService::class)->priceForAdvertiser($site, null, 3);
        // 20% of €113 = €90.40, floored at publisher €100. Bulk 10% would be €101.70 — sale wins.
        $this->assertSame(20.0, $expected['discount_percent_nominal']);
        $this->assertSame(100.0, $expected['total']);

        $cart = [[
            'id' => $site->id,
            'name' => $site->site_name,
            'quantity' => 3,
            'bulk_pack' => true,
            'content_submission_id' => $slots[0]->id,
            'content_submission_ids' => [$slots[0]->id, $slots[1]->id, $slots[2]->id],
        ]];

        $html = $this->checkoutPage($cart);
        $this->assertStringContainsString('Site offer', $html);
        $this->assertStringNotContainsString('Bulk deal', $html);
        $this->assertStringContainsString('€100.00', $html);
        $this->assertStringContainsString('€113.00', $html);

        $this->payWallet($cart, 'BOTH1');

        $items = OrderItem::query()
            ->whereHas('order', fn ($q) => $q->where('reference_code', 'BOTH1'))
            ->get();
        $this->assertCount(3, $items);
        foreach ($items as $item) {
            $this->assertEquals(100.0, (float) $item->price);
            $this->assertEquals(100.0, (float) $item->publisher_price);
            $this->assertEquals(0.0, (float) $item->platform_fee_amount);
        }
        $this->assertEquals(300.0, (float) Order::where('reference_code', 'BOTH1')->sum('total_amount'));
    }

    public function test_qty_two_on_bulk_site_does_not_get_bulk_rate(): void
    {
        $site = $this->makeSite('nobulk', 100, [
            'bulk_discount_enabled' => true,
            'bulk_discount_percent' => 10,
        ]);
        $a = $this->createApprovedSubmission($this->advertiser, null, 0, 'single a', 'https://example.com/a');
        $b = $this->createApprovedSubmission($this->advertiser, null, 1, 'single b', 'https://example.com/b');

        $cart = [[
            'id' => $site->id,
            'name' => $site->site_name,
            'quantity' => 2,
            'content_submission_id' => $a->id,
            'content_submission_ids' => [$a->id, $b->id],
        ]];

        $html = $this->checkoutPage($cart);
        $this->assertStringNotContainsString('Bulk deal', $html);
        $this->assertStringContainsString('€113.00', $html);
        $this->assertStringNotContainsString('You save', $html);

        $this->payWallet($cart, 'QTY2');

        $items = OrderItem::query()
            ->whereHas('order', fn ($q) => $q->where('reference_code', 'QTY2'))
            ->get();
        $this->assertCount(2, $items);
        foreach ($items as $item) {
            $this->assertEquals(113.0, (float) $item->price);
        }
    }
}
