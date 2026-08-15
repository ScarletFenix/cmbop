<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\OrderPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class CardCheckoutCreatesPendingOrdersTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        Mockery::close();
        parent::tearDown();
    }

    private function advertiser(): User
    {
        $role = Role::create(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach($role->id);
        $user->active_role_id = $role->id;
        $user->save();

        return $user->fresh();
    }

    private function activeSite(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Card Test Site',
            'site_url' => 'https://card-test.example',
            'domain' => 'card-test.example',
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
            'description' => 'Test site for card checkout',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function fakeStripeCheckoutSession(string $sessionId = 'cs_test_pending_orders'): void
    {
        $this->fakeStripeCheckoutSessions([$sessionId]);
    }

    /**
     * @param  list<string>  $sessionIds
     */
    private function fakeStripeCheckoutSessions(array $sessionIds): void
    {
        config([
            'services.stripe.secret' => 'sk_test_fake_key_for_unit_tests',
            'services.stripe.key' => 'pk_test_fake_key_for_unit_tests',
        ]);

        $client = Mockery::mock(ClientInterface::class);
        $returns = [];
        foreach ($sessionIds as $sessionId) {
            $returns[] = [json_encode([
                'id' => 'cus_test_'.substr($sessionId, -8),
                'object' => 'customer',
                'email' => 'test@example.com',
                'livemode' => false,
            ], JSON_THROW_ON_ERROR), 200, []];
            $returns[] = [json_encode([
                'id' => $sessionId,
                'object' => 'checkout.session',
                'url' => 'https://checkout.stripe.com/c/pay/'.$sessionId,
                'payment_status' => 'unpaid',
                'mode' => 'payment',
                'metadata' => [],
            ], JSON_THROW_ON_ERROR), 200, []];
        }

        $client->shouldReceive('request')
            ->times(count($returns))
            ->andReturn(...$returns);

        ApiRequestor::setHttpClient($client);
    }

    public function test_card_checkout_creates_pending_orders_before_stripe_redirect(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->advertiser();
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        $publisher = User::factory()->create(['email_verified_at' => now()]);
        $publisher->roles()->attach($publisherRole->id);

        $site = $this->activeSite($publisher);
        $submission = $this->createApprovedSubmission($advertiser, $site->id);

        $this->fakeStripeCheckoutSession('cs_test_card_fix_2');

        $response = $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'price' => 9999,
                    'sensitive_type' => null,
                ]],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'card',
                'reference_code' => 'CARD42',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$submission->id],
                ],
            ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'requires_payment' => true,
                'reference_code' => 'CARD42',
                'session_id' => 'cs_test_card_fix_2',
            ])
            ->assertJsonStructure(['checkout_url']);

        // Add Funds style: no order rows until Stripe payment succeeds.
        $this->assertSame(0, Order::where('reference_code', 'CARD42')->count());
        $this->assertNotNull(Cache::get('pending_card_checkout:CARD42'));
        $this->assertFalse(session()->missing('cart'));
        $this->assertSame('CARD42', session('pending_card_reference'));
    }

    public function test_card_checkout_rotates_reference_when_prior_orders_exist(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->advertiser();
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        $publisher = User::factory()->create(['email_verified_at' => now()]);
        $publisher->roles()->attach($publisherRole->id);
        $site = $this->activeSite($publisher);
        $submission = $this->createApprovedSubmission($advertiser, $site->id);

        Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'CARD42',
            'subtotal' => 40,
            'tax' => 0,
            'total_amount' => 40,
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'status' => 'pending',
        ]);

        $this->fakeStripeCheckoutSession('cs_test_card_rotate');

        $response = $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'price' => 100,
                    'sensitive_type' => null,
                ]],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'card',
                'reference_code' => 'CARD42',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$submission->id],
                ],
            ]);

        $response->assertOk()->assertJson([
            'success' => true,
            'requires_payment' => true,
        ]);
        $newRef = (string) $response->json('reference_code');
        $this->assertNotSame('CARD42', $newRef);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $newRef);
        $this->assertNull(Cache::get('pending_card_checkout:CARD42'));
        $this->assertNotNull(Cache::get('pending_card_checkout:'.$newRef));
    }

    public function test_card_checkout_rotates_reference_when_open_stripe_session_exists(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->advertiser();
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        $publisher = User::factory()->create(['email_verified_at' => now()]);
        $publisher->roles()->attach($publisherRole->id);
        $site = $this->activeSite($publisher);
        $submission = $this->createApprovedSubmission($advertiser, $site->id);
        $cart = [[
            'id' => $site->id,
            'name' => $site->site_name,
            'quantity' => 1,
            'price' => 100,
            'sensitive_type' => null,
        ]];
        $payload = [
            'payment_method' => 'card',
            'reference_code' => 'CARD42',
            'publication_mode' => 'immediate',
            'content_submissions' => [
                $site->id => [$submission->id],
            ],
        ];

        $this->fakeStripeCheckoutSession('cs_test_open_first');
        $this->actingAs($advertiser)
            ->withSession(['cart' => $cart])
            ->postJson(route('advertiser.checkout.process'), $payload)
            ->assertOk()
            ->assertJsonPath('reference_code', 'CARD42');

        $this->fakeStripeCheckoutSession('cs_test_open_second');
        $second = $this->actingAs($advertiser)
            ->withSession(['cart' => $cart])
            ->postJson(route('advertiser.checkout.process'), $payload)
            ->assertOk()
            ->assertJson(['success' => true, 'requires_payment' => true]);

        $newRef = (string) $second->json('reference_code');
        $this->assertNotSame('CARD42', $newRef);
        $this->assertNotNull(Cache::get('pending_card_checkout:CARD42'));
        $this->assertNotNull(Cache::get('pending_card_checkout:'.$newRef));
        $this->assertSame('cs_test_open_first', Cache::get('pending_card_checkout:CARD42')['stripe_session_id'] ?? null);
    }

    public function test_card_checkout_rolls_back_pending_orders_when_stripe_fails(): void
    {
        config(['content_moderation.enabled' => false]);
        config(['services.stripe.secret' => 'sk_test_fake_key_for_unit_tests']);

        $advertiser = $this->advertiser();
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        $publisher = User::factory()->create(['email_verified_at' => now()]);
        $publisher->roles()->attach($publisherRole->id);
        $site = $this->activeSite($publisher);
        $submission = $this->createApprovedSubmission($advertiser, $site->id);

        $client = Mockery::mock(ClientInterface::class);
        $customerBody = json_encode([
            'id' => 'cus_test_fail',
            'object' => 'customer',
            'email' => 'test@example.com',
        ], JSON_THROW_ON_ERROR);
        // Customer create succeeds; Checkout Session (customer) fails; guest retry also fails.
        $client->shouldReceive('request')
            ->once()
            ->andReturn([$customerBody, 200, []]);
        $client->shouldReceive('request')
            ->twice()
            ->andThrow(new \Exception('stripe unavailable'));
        ApiRequestor::setHttpClient($client);

        $response = $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'sensitive_type' => null,
                ]],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'card',
                'reference_code' => 'FAIL99',
                'content_submissions' => [
                    $site->id => [$submission->id],
                ],
            ]);

        $response->assertOk()->assertJson(['success' => false]);
        $this->assertSame(0, Order::where('reference_code', 'FAIL99')->count());
        $this->assertNull(Cache::get('pending_card_checkout:FAIL99'));
    }

    public function test_card_checkout_falls_back_to_guest_session_when_customer_session_fails(): void
    {
        config(['content_moderation.enabled' => false]);
        config([
            'services.stripe.secret' => 'sk_test_fake_key_for_unit_tests',
            'services.stripe.key' => 'pk_test_fake_key_for_unit_tests',
        ]);

        $advertiser = $this->advertiser();
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        $publisher = User::factory()->create(['email_verified_at' => now()]);
        $publisher->roles()->attach($publisherRole->id);
        $site = $this->activeSite($publisher);
        $submission = $this->createApprovedSubmission($advertiser, $site->id);

        $customerBody = json_encode([
            'id' => 'cus_test_fallback',
            'object' => 'customer',
            'email' => 'test@example.com',
        ], JSON_THROW_ON_ERROR);
        $sessionBody = json_encode([
            'id' => 'cs_test_guest_fallback',
            'object' => 'checkout.session',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_guest_fallback',
            'payment_status' => 'unpaid',
            'mode' => 'payment',
            'metadata' => [],
        ], JSON_THROW_ON_ERROR);

        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->andReturn([$customerBody, 200, []]);
        $client->shouldReceive('request')
            ->once()
            ->andThrow(new \Exception('customer session rejected'));
        $client->shouldReceive('request')
            ->once()
            ->andReturn([$sessionBody, 200, []]);
        ApiRequestor::setHttpClient($client);

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'sensitive_type' => null,
                ]],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'card',
                'reference_code' => 'FALLBK',
                'content_submissions' => [
                    $site->id => [$submission->id],
                ],
            ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'requires_payment' => true,
                'session_id' => 'cs_test_guest_fallback',
            ]);

        $this->assertSame(0, Order::where('reference_code', 'FALLBK')->count());
        $this->assertNotNull(Cache::get('pending_card_checkout:FALLBK'));
    }

    public function test_card_checkout_rejects_when_stripe_not_configured(): void
    {
        config(['content_moderation.enabled' => false]);
        config([
            'services.stripe.secret' => '',
            'services.stripe.key' => '',
        ]);

        $advertiser = $this->advertiser();
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        $publisher = User::factory()->create(['email_verified_at' => now()]);
        $publisher->roles()->attach($publisherRole->id);
        $site = $this->activeSite($publisher);
        $submission = $this->createApprovedSubmission($advertiser, $site->id);

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'sensitive_type' => null,
                ]],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'card',
                'reference_code' => 'NOCFG1',
                'content_submissions' => [
                    $site->id => [$submission->id],
                ],
            ])
            ->assertStatus(503)
            ->assertJsonPath('success', false);

        $this->assertSame(0, Order::where('reference_code', 'NOCFG1')->count());
    }

    public function test_second_card_pay_without_cancel_does_not_steal_open_session_bonus(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->advertiser();
        $wallet = $this->fundAdvertiserWallet($advertiser, 20);
        $wallet->update(['bonus_balance' => 20]);
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        $publisher = User::factory()->create(['email_verified_at' => now()]);
        $publisher->roles()->attach($publisherRole->id);
        $site = $this->activeSite($publisher);
        $submission = $this->createApprovedSubmission($advertiser, $site->id);

        $this->fakeStripeCheckoutSessions(['cs_bonus_first', 'cs_bonus_retry']);

        $payload = [
            'payment_method' => 'card',
            'reference_code' => 'BONUS1',
            'publication_mode' => 'immediate',
            'use_bonus' => '1',
            'content_submissions' => [
                $site->id => [$submission->id],
            ],
        ];
        $session = [
            'cart' => [[
                'id' => $site->id,
                'name' => $site->site_name,
                'quantity' => 1,
                'price' => 100,
                'sensitive_type' => null,
            ]],
        ];

        $first = $this->actingAs($advertiser)->withSession($session)
            ->postJson(route('advertiser.checkout.process'), $payload)
            ->assertOk()
            ->assertJsonPath('success', true);

        $retry = $this->actingAs($advertiser)->withSession($session)
            ->postJson(route('advertiser.checkout.process'), $payload)
            ->assertOk()
            ->assertJsonPath('success', true);

        $firstRef = (string) $first->json('reference_code');
        $retryRef = (string) $retry->json('reference_code');
        $this->assertNotSame('', $firstRef);
        $this->assertNotSame('', $retryRef);
        $this->assertNotSame($firstRef, $retryRef);

        $firstPackage = app(OrderPaymentService::class)->getPendingCheckout($firstRef);
        $retryPackage = app(OrderPaymentService::class)->getPendingCheckout($retryRef);
        $this->assertNotNull($firstPackage);
        $this->assertNotNull($retryPackage);
        $this->assertEqualsWithDelta(20.0, (float) ($firstPackage['bonus_applied'] ?? 0), 0.01);
        $this->assertEqualsWithDelta(0.0, (float) ($retryPackage['bonus_applied'] ?? 0), 0.01);

        $wallet = Wallet::query()
            ->where('user_id', $advertiser->id)
            ->where('role_id', Wallet::advertiserRoleId())
            ->first();
        $this->assertNotNull($wallet);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_balance, 0.01);
    }
}
