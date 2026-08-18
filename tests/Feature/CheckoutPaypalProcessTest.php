<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\OrderPaymentService;
use App\Services\PaypalCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class CheckoutPaypalProcessTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function publisher(): User
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function activeSite(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'PayPal Test Site',
            'site_url' => 'https://paypal-test.example',
            'domain' => 'paypal-test.example',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 100.00,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Test site for PayPal checkout',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function enablePaypal(): void
    {
        config([
            'services.paypal.enabled' => true,
            'services.paypal.mode' => 'sandbox',
            'services.paypal.client_id' => 'paypal-client-test',
            'services.paypal.secret' => 'paypal-secret-test',
            'services.paypal.webhook_id' => 'WH-TEST-1',
            'services.paypal.base_url' => null,
        ]);
    }

    private function fakePaypalCreateOrder(string $orderId = 'PO-CHECKOUT-1'): void
    {
        Http::fake(function ($request) use ($orderId) {
            $url = $request->url();

            if (str_contains($url, '/v1/oauth2/token')) {
                return Http::response([
                    'access_token' => 'tok_test',
                    'expires_in' => 300,
                    'token_type' => 'Bearer',
                ], 200);
            }

            if (str_ends_with(parse_url($url, PHP_URL_PATH) ?: '', '/v2/checkout/orders')) {
                return Http::response([
                    'id' => $orderId,
                    'status' => 'CREATED',
                    'links' => [
                        ['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token='.$orderId],
                    ],
                ], 201);
            }

            return Http::response(['name' => 'RESOURCE_NOT_FOUND'], 404);
        });
    }

    public function test_checkout_page_shows_paypal_tile_disabled_when_not_configured(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->advertiser();
        $site = $this->activeSite($this->publisher());
        $sub = $this->createApprovedSubmission($advertiser, $site->id);

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $sub->id,
                ]],
            ])
            ->get(route('advertiser.checkout'))
            ->assertOk()
            ->assertSee('data-method="paypal"', false)
            ->assertSee('data-paypal-disabled="1"', false)
            ->assertSee('PayPal is not configured', false);
    }

    public function test_checkout_page_enables_paypal_tile_when_configured(): void
    {
        config(['content_moderation.enabled' => false]);
        $this->enablePaypal();

        $advertiser = $this->advertiser();
        $site = $this->activeSite($this->publisher());
        $sub = $this->createApprovedSubmission($advertiser, $site->id);

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $sub->id,
                ]],
            ])
            ->get(route('advertiser.checkout'))
            ->assertOk()
            ->assertSee('data-method="paypal"', false)
            ->assertDontSee('data-paypal-disabled="1"', false)
            ->assertDontSee('id="paypalNotConfiguredAlert"', false)
            ->assertSee('Secure PayPal checkout', false);
    }

    public function test_process_order_rejects_paypal_when_not_configured(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->advertiser();
        $site = $this->activeSite($this->publisher());
        $sub = $this->createApprovedSubmission($advertiser, $site->id);

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $sub->id,
                ]],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'paypal',
                'reference_code' => 'PP-OFF',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$sub->id],
                ],
            ])
            ->assertStatus(503)
            ->assertJsonPath('success', false);

        $this->assertSame(0, Order::where('reference_code', 'PP-OFF')->count());
    }

    public function test_process_order_paypal_creates_approve_url_without_order_rows(): void
    {
        config(['content_moderation.enabled' => false]);
        $this->enablePaypal();
        $this->fakePaypalCreateOrder('PO-CHECKOUT-42');

        $advertiser = $this->advertiser();
        $site = $this->activeSite($this->publisher());
        $sub = $this->createApprovedSubmission($advertiser, $site->id);

        $response = $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $sub->id,
                    'price' => 9999,
                ]],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'paypal',
                'reference_code' => 'PP42',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$sub->id],
                ],
            ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'requires_payment' => true,
                'reference_code' => 'PP42',
                'paypal_order_id' => 'PO-CHECKOUT-42',
            ])
            ->assertJsonPath('checkout_url', 'https://www.sandbox.paypal.com/checkoutnow?token=PO-CHECKOUT-42');

        $this->assertSame(0, Order::where('reference_code', 'PP42')->count());
        $package = app(OrderPaymentService::class)->getPendingCheckout('PP42');
        $this->assertIsArray($package);
        $this->assertSame('paypal', $package['payment_method'] ?? null);
        $this->assertSame('PO-CHECKOUT-42', $package['paypal_order_id'] ?? null);
        $this->assertSame('PP42', session('pending_paypal_reference'));
        $this->assertNotNull(Cache::get('pending_card_checkout:PP42'));

        Http::assertSent(function ($request) {
            if (! str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '/v2/checkout/orders')) {
                return false;
            }

            $body = $request->data();

            return ($body['intent'] ?? null) === 'CAPTURE'
                && ($body['purchase_units'][0]['invoice_id'] ?? null) === 'PP42'
                && ($body['purchase_units'][0]['custom_id'] ?? null) === PaypalCheckoutService::customId(
                    PaypalCheckoutService::TYPE_ORDER_CHECKOUT,
                    auth()->id(),
                    'PP42'
                )
                && str_contains((string) ($body['application_context']['return_url'] ?? ''), '/advertiser/checkout/paypal/return')
                && str_contains((string) ($body['application_context']['cancel_url'] ?? ''), '/advertiser/checkout/paypal/cancel');
        });
    }

    public function test_process_order_paypal_is_not_fund_wallet_first(): void
    {
        config(['content_moderation.enabled' => false]);
        $this->enablePaypal();
        $this->fakePaypalCreateOrder();

        $advertiser = $this->advertiser();
        $site = $this->activeSite($this->publisher());
        $sub = $this->createApprovedSubmission($advertiser, $site->id);

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $sub->id,
                ]],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'paypal',
                'reference_code' => 'PP-RAIL',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$sub->id],
                ],
            ])
            ->assertOk()
            ->assertJsonMissing(['code' => 'fund_wallet_first']);
    }

    public function test_paypal_cancel_route_returns_to_checkout(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->advertiser();
        $site = $this->activeSite($this->publisher());
        $sub = $this->createApprovedSubmission($advertiser, $site->id);

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $sub->id,
                ]],
            ])
            ->get(route('advertiser.checkout.paypal.cancel', ['ref' => 'PP-CAN']))
            ->assertRedirect(route('advertiser.checkout', ['canceled' => 1, 'ref' => 'PP-CAN']));
    }
}
