<?php

namespace Tests\Feature;

use App\Mail\OrderPaymentConfirmed;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Billing\BillingDocumentService;
use App\Services\CartPricingService;
use App\Services\Marketplace\LinkGuaranteeService;
use App\Services\Orders\OrderClawbackService;
use App\Support\LinkInsertionProduct;
use App\Support\PublicFaq;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LinkInsertionProductTest extends TestCase
{
    use RefreshDatabase;

    private User $advertiser;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        config(['content_moderation.enabled' => false]);
        Mail::fake();

        $advertiserRole = Role::firstOrCreate(['name' => 'advertiser']);
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        Role::firstOrCreate(['name' => 'admin']);

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

    private function makeSite(string $slug, float $listingLiPrice = 40, array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'LI '.$slug,
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
            'price' => 100,
            'publication_time' => '6months',
            'link_type' => 'dofollow',
            'description' => 'Link insertion listing.',
            'verified' => true,
            'active' => true,
            'accepts_link_insertions' => true,
            'listing_link_insertion_price' => $listingLiPrice,
            'link_insertion_price' => $listingLiPrice,
            'link_insertion_link_type' => 'dofollow',
            'link_insertion_delivery_days' => 7,
        ], $overrides));
    }

    public function test_faq_copy_is_permanent_and_within_word_budget(): void
    {
        $this->assertSame(
            'Permanent — your link stays live as long as the article remains published.',
            LinkInsertionProduct::PERMANENT_COPY
        );
        $this->assertSame(15.0, LinkInsertionProduct::FROM_EUR);

        $answer = LinkInsertionProduct::faqAnswer();
        $words = preg_split('/\s+/u', trim($answer)) ?: [];
        $this->assertGreaterThanOrEqual(40, count($words), $answer);
        $this->assertLessThanOrEqual(80, count($words), $answer);
        $this->assertStringContainsString('from', $answer);
        $this->assertStringContainsString('15', $answer);
        $this->assertStringContainsString(LinkInsertionProduct::PERMANENT_COPY, $answer);
        $this->assertStringContainsString('10%', $answer);
        $this->assertStringContainsString('publisher rejects', $answer);
        $this->assertStringNotContainsString('12-month', $answer);
        $this->assertStringNotContainsString('12 months', $answer);

        $found = collect(PublicFaq::items())->firstWhere('q', LinkInsertionProduct::faqQuestion());
        $this->assertNotNull($found);
        $this->assertSame($answer, $found['a']);
    }

    public function test_public_lander_faq_and_llms_use_owner_copy(): void
    {
        $html = $this->get('/link-insertion')->assertOk()->getContent();
        $this->assertStringContainsString(LinkInsertionProduct::landerH1(), $html);
        $this->assertStringContainsString(LinkInsertionProduct::PERMANENT_COPY, $html);
        $this->assertStringContainsString('from €15', $html);
        $this->assertStringContainsString('Publishers set their own', $html);
        $this->assertStringContainsString('10%', $html);
        $this->assertStringContainsString('casino', strtolower($html));
        $this->assertStringNotContainsString('12-month', $html);

        $this->get('/link-insertions')->assertRedirect('/link-insertion');
        $this->get('/buy-link-insertion')->assertRedirect('/link-insertion');

        $faq = $this->get('/faq')->assertOk()->getContent();
        $this->assertStringContainsString(LinkInsertionProduct::faqQuestion(), $faq);
        $this->assertStringContainsString(LinkInsertionProduct::PERMANENT_COPY, $faq);

        $llms = $this->get('/llms.txt')->assertOk()->getContent();
        $this->assertStringContainsString('/link-insertion', $llms);
        $this->assertStringContainsString('from €15', $llms);
        $this->assertStringContainsString('Permanent', $llms);
    }

    public function test_schema_has_link_insertion_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('sites', 'accepts_link_insertions'));
        $this->assertTrue(Schema::hasColumn('sites', 'listing_link_insertion_price'));
        $this->assertTrue(Schema::hasColumn('order_items', 'product_type'));
        $this->assertTrue(Schema::hasColumn('order_items', 'preferred_article_url'));
        $this->assertTrue(Schema::hasColumn('order_items', 'link_guarantee'));
        $this->assertTrue(Schema::hasColumn('order_items', 'link_guarantee_fee'));
        $this->assertTrue(Schema::hasColumn('order_items', 'link_guarantee_covered_amount'));
    }

    public function test_catalog_add_to_cart_and_checkout_form_show_optional_addon(): void
    {
        $site = $this->makeSite('checkout-form');

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), [
                'id' => $site->id,
                'product_type' => LinkInsertionProduct::TYPE,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.checkout'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Link insertion', $html);
        $this->assertStringContainsString(LinkInsertionProduct::PERMANENT_COPY, $html);
        $this->assertStringContainsString('Preferred article URL', $html);
        $this->assertStringContainsString('Add '.LinkInsertionProduct::GUARANTEE_LABEL, $html);
        $this->assertStringContainsString('10%', $html);
        $this->assertStringContainsString('no refund if the live link is later removed', strtolower($html));
        $this->assertStringNotContainsString('id="linkGuarantee"', $html);
    }

    public function test_wallet_checkout_with_addon_stores_item_flag_and_ten_percent_fee(): void
    {
        $site = $this->makeSite('with-addon');
        $priced = app(CartPricingService::class)->priceForAdvertiser(
            $site,
            null,
            1,
            'none',
            false,
            null,
            LinkInsertionProduct::TYPE
        );
        $placement = round((float) $priced['total'], 2);
        $fee = round($placement * 0.10, 2);
        $expected = round($placement + $fee, 2);

        $response = $this->actingAs($this->advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'product_type' => LinkInsertionProduct::TYPE,
                ]],
                'checkout_schedule' => ['mode' => 'immediate', 'timezone' => 'UTC'],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'LIADDON1',
                'publication_mode' => 'immediate',
                'link_guarantee_items' => [$site->id => 1],
                'preferred_article_url' => [$site->id => 'https://'.$site->domain.'/existing'],
                'insertion_target_url' => [$site->id => 'https://advertiser.example/offer'],
                'insertion_anchor_text' => [$site->id => 'garden tools'],
                'insertion_sentence_same' => [$site->id => 1],
            ]);

        $response->assertOk()->assertJson(['success' => true]);

        $order = Order::query()->where('reference_code', 'LIADDON1')->firstOrFail();
        $item = $order->items()->firstOrFail();

        $this->assertSame(LinkInsertionProduct::TYPE, $item->product_type);
        $this->assertTrue((bool) $item->link_guarantee);
        $this->assertEquals($fee, (float) $item->link_guarantee_fee);
        $this->assertEquals($placement, (float) $item->link_guarantee_covered_amount);
        $this->assertTrue((bool) $order->link_guarantee);
        $this->assertEquals($fee, (float) $order->link_guarantee_fee);
        $this->assertEquals($expected, (float) $order->total_amount);
        $this->assertSame('https://'.$site->domain.'/existing', $item->preferred_article_url);
        $this->assertSame('https://advertiser.example/offer', $item->target_url);
        $this->assertSame('garden tools', $item->anchor_text);
        $this->assertTrue((bool) $item->insertion_sentence_same);
        $this->assertSame(LinkInsertionProduct::CONTENT_LINK, $item->content_link);
        $this->assertStringContainsString('link insertions', $order->linkGuaranteeTermsNote());
        $this->assertStringContainsString('Permanent', $order->linkGuaranteeTermsNote());
    }

    public function test_wallet_checkout_without_addon_does_not_charge_or_allow_post_live_refund(): void
    {
        $site = $this->makeSite('no-addon');
        $priced = app(CartPricingService::class)->priceForAdvertiser(
            $site,
            null,
            1,
            'none',
            false,
            null,
            LinkInsertionProduct::TYPE
        );
        $placement = round((float) $priced['total'], 2);

        $response = $this->actingAs($this->advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'product_type' => LinkInsertionProduct::TYPE,
                ]],
                'checkout_schedule' => ['mode' => 'immediate', 'timezone' => 'UTC'],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'LINOADDON',
                'publication_mode' => 'immediate',
                'link_guarantee' => 1,
                'preferred_article_url' => [$site->id => 'https://'.$site->domain.'/existing'],
                'insertion_target_url' => [$site->id => 'https://advertiser.example/offer'],
                'insertion_anchor_text' => [$site->id => 'garden tools'],
                'insertion_sentence_new' => [$site->id => 1],
                'insertion_sentence_new_text' => [$site->id => 'Readers can also try garden tools today.'],
            ]);

        $response->assertOk()->assertJson(['success' => true]);

        $order = Order::query()->where('reference_code', 'LINOADDON')->firstOrFail();
        $item = $order->items()->firstOrFail();

        $this->assertFalse((bool) $item->link_guarantee);
        $this->assertEquals(0.0, (float) $item->link_guarantee_fee);
        $this->assertFalse((bool) $order->link_guarantee);
        $this->assertEquals($placement, (float) $order->total_amount);
        $this->assertTrue((bool) $item->insertion_sentence_new);

        $order->update([
            'status' => 'completed',
            'payment_status' => 'paid',
            'completed_at' => now()->subDay(),
        ]);
        $item->update([
            'completed_at' => now()->subDay(),
            'live_url' => 'https://'.$site->domain.'/live',
        ]);

        $this->assertFalse(app(OrderClawbackService::class)->canOpenDispute($order->fresh(['items']), $item->fresh()));
    }

    public function test_paid_link_insertion_guarantee_never_expires(): void
    {
        $site = $this->makeSite('permanent-g');
        $order = Order::create([
            'user_id' => $this->advertiser->id,
            'order_number' => 'ORD-LI-PERM',
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'completed',
            'paid_at' => now()->subDay(),
            'completed_at' => now()->subDay(),
            'link_guarantee' => true,
            'link_guarantee_fee' => 4.55,
            'link_guarantee_covered_amount' => 45.5,
            'link_guarantee_status' => LinkGuaranteeService::STATUS_PENDING,
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'product_type' => LinkInsertionProduct::TYPE,
            'content_link' => LinkInsertionProduct::CONTENT_LINK,
            'price' => 50,
            'publisher_price' => 40,
            'publisher_amount' => 40,
            'platform_fee_amount' => 5.5,
            'link_guarantee' => true,
            'link_guarantee_fee' => 4.55,
            'link_guarantee_covered_amount' => 45.5,
            'completed_at' => now()->subDay(),
            'live_url' => 'https://'.$site->domain.'/live',
            'target_url' => 'https://advertiser.example/offer',
        ]);

        app(LinkGuaranteeService::class)->activateIfCompleted($order->fresh(['items']));
        $order->refresh();

        $this->assertSame(LinkGuaranteeService::STATUS_ACTIVE, $order->link_guarantee_status);
        $this->assertNull($order->link_guarantee_ends_at);
    }

    public function test_invoice_shows_link_guarantee_as_separate_line(): void
    {
        Storage::fake('local');
        $site = $this->makeSite('invoice');
        $order = Order::create([
            'user_id' => $this->advertiser->id,
            'order_number' => 'ORD-LI-INV',
            'subtotal' => 50.05,
            'tax' => 0,
            'total_amount' => 50.05,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
            'link_guarantee' => true,
            'link_guarantee_fee' => 4.55,
            'link_guarantee_covered_amount' => 45.5,
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'product_type' => LinkInsertionProduct::TYPE,
            'content_link' => LinkInsertionProduct::CONTENT_LINK,
            'price' => 50.05,
            'publisher_price' => 40,
            'publisher_amount' => 40,
            'platform_fee_amount' => 5.5,
            'link_guarantee' => true,
            'link_guarantee_fee' => 4.55,
            'link_guarantee_covered_amount' => 45.5,
            'target_url' => 'https://advertiser.example/offer',
            'anchor_text' => 'garden tools',
        ]);

        $this->assertSame(1, $order->items()->count());
        $service = app(BillingDocumentService::class);
        $create = new \ReflectionMethod($service, 'createDocument');
        $create->setAccessible(true);
        $invoice = $create->invoke(
            $service,
            $order->fresh(['items', 'user']),
            Invoice::TYPE_TAX_INVOICE,
            Invoice::STATUS_PAID,
            ['paid_at' => now()]
        );
        $this->assertInstanceOf(Invoice::class, $invoice);
        $lines = is_array($invoice->line_items) ? $invoice->line_items : [];
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('Link insertion', (string) $lines[0]['description']);
        $this->assertEquals(45.5, (float) $lines[0]['unit_price']);
        $this->assertSame('Link guarantee (permanent)', $lines[1]['description']);
        $this->assertEquals(4.55, (float) $lines[1]['unit_price']);
        $this->assertEquals(50.05, round((float) $lines[0]['line_total'] + (float) $lines[1]['line_total'], 2));
    }

    public function test_order_payment_email_mentions_link_insertion_and_addon(): void
    {
        $site = $this->makeSite('mail');
        $order = Order::create([
            'user_id' => $this->advertiser->id,
            'order_number' => 'ORD-LI-MAIL',
            'subtotal' => 50.05,
            'tax' => 0,
            'total_amount' => 50.05,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
            'link_guarantee' => true,
            'link_guarantee_fee' => 4.55,
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'product_type' => LinkInsertionProduct::TYPE,
            'content_link' => LinkInsertionProduct::CONTENT_LINK,
            'price' => 50.05,
            'link_guarantee' => true,
            'link_guarantee_fee' => 4.55,
            'preferred_article_url' => 'https://'.$site->domain.'/existing',
            'target_url' => 'https://advertiser.example/offer',
            'anchor_text' => 'garden tools',
        ]);

        $html = (new OrderPaymentConfirmed($order->fresh(['items', 'user'])))->render();
        $this->assertStringContainsString('Link insertion', $html);
        $this->assertStringContainsString('Link guarantee', $html);
    }
}
