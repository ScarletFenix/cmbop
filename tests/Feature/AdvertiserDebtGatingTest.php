<?php

namespace Tests\Feature;

use App\Models\ContentSubmission;
use App\Models\Order;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\PaypalCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class AdvertiserDebtGatingTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['content_moderation.enabled' => false]);
    }

    private function userWithRole(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user->fresh();
    }

    private function activeSite(User $publisher, float $price = 40): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Debt Gate Site',
            'site_url' => 'https://debt-gate.example',
            'domain' => 'debt-gate.example',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => $price,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Advertiser debt gating fixture',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function advertiserWallet(User $advertiser, float $balance, float $debt = 0): Wallet
    {
        return Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => $balance,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'debt_balance' => $debt,
            'currency' => 'EUR',
        ]);
    }

    /**
     * @return array{0: User, 1: Site, 2: ContentSubmission}
     */
    private function checkoutCart(User $advertiser): array
    {
        $site = $this->activeSite($this->userWithRole('publisher'));
        $sub = $this->createApprovedSubmission($advertiser, $site->id);

        return [$advertiser, $site, $sub];
    }

    public function test_checkout_page_warns_when_advertiser_has_debt(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $this->advertiserWallet($advertiser, 100, 17);
        [, $site, $sub] = $this->checkoutCart($advertiser);

        $html = $this->actingAs($advertiser)
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
            ->getContent();

        $this->assertStringContainsString('outstanding debt from a refunded deposit', $html);
        $this->assertStringContainsString(route('advertiser.add-funds', [], false), $html);
        $this->assertStringContainsString("data.code === 'wallet_debt'", $html);
    }

    public function test_process_order_is_blocked_while_advertiser_has_debt(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $wallet = $this->advertiserWallet($advertiser, 100, 17);
        [, $site, $sub] = $this->checkoutCart($advertiser);

        $session = [
            'cart' => [[
                'id' => $site->id,
                'name' => $site->site_name,
                'quantity' => 1,
                'content_submission_id' => $sub->id,
            ]],
            'checkout_content_submission_id' => $sub->id,
            'checkout_schedule' => ['mode' => 'immediate', 'timezone' => 'UTC'],
        ];

        foreach (['wallet', 'card', 'paypal'] as $method) {
            if ($method === 'paypal') {
                config([
                    'services.paypal.enabled' => true,
                    'services.paypal.client_id' => 'paypal-client-test',
                    'services.paypal.secret' => 'paypal-secret-test',
                ]);
            }
            if ($method === 'card') {
                config([
                    'services.stripe.secret' => 'sk_test_fake_key_for_unit_tests',
                    'services.stripe.key' => 'pk_test_fake_key_for_unit_tests',
                ]);
            }

            $this->actingAs($advertiser)
                ->withSession($session)
                ->postJson(route('advertiser.checkout.process'), [
                    'payment_method' => $method,
                    'reference_code' => 'DEBT-'.$method,
                    'publication_mode' => 'immediate',
                    'content_submissions' => [
                        $site->id => [$sub->id],
                    ],
                ])
                ->assertStatus(422)
                ->assertJsonPath('success', false)
                ->assertJsonPath('code', 'wallet_debt');
        }

        $this->assertSame(0, Order::query()->count());
        $this->assertEqualsWithDelta(100.0, (float) $wallet->fresh()->balance, 0.01);
        $this->assertEqualsWithDelta(17.0, (float) $wallet->fresh()->debt_balance, 0.01);
    }

    public function test_clearing_debt_with_a_deposit_unlocks_wallet_checkout(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $wallet = $this->advertiserWallet($advertiser, 100, 17);
        [, $site, $sub] = $this->checkoutCart($advertiser);

        $wallet->credit(17);
        $wallet->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $wallet->debt_balance, 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $wallet->balance, 0.01);

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $sub->id,
                ]],
                'checkout_content_submission_id' => $sub->id,
                'checkout_schedule' => ['mode' => 'immediate', 'timezone' => 'UTC'],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'DEBT-CLEARED',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$sub->id],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(1, Order::where('reference_code', 'DEBT-CLEARED')->count());
    }

    public function test_add_funds_still_works_with_advertiser_debt(): void
    {
        config([
            'services.paypal.enabled' => true,
            'services.paypal.mode' => 'sandbox',
            'services.paypal.client_id' => 'paypal-client-test',
            'services.paypal.secret' => 'paypal-secret-test',
            'services.paypal.base_url' => null,
        ]);
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/v1/oauth2/token')) {
                return Http::response([
                    'access_token' => 'tok_test',
                    'expires_in' => 300,
                    'token_type' => 'Bearer',
                ], 200);
            }
            if (str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '/v2/checkout/orders')) {
                return Http::response([
                    'id' => 'PO-DEBT-ADD',
                    'status' => 'CREATED',
                    'links' => [
                        ['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=PO-DEBT-ADD'],
                    ],
                ], 201);
            }

            return Http::response(['name' => 'RESOURCE_NOT_FOUND'], 404);
        });

        $advertiser = $this->userWithRole('advertiser');
        $this->advertiserWallet($advertiser, 80, 17);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.add-funds'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('outstanding debt from a refunded deposit', $html);
        $this->assertStringContainsString('New deposits pay that debt first', $html);
        $this->assertMatchesRegularExpression('/id="withdrawOpenBtn"[^>]*\bdisabled\b/', $html);

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.add-funds.paypal.create'), [
                'amount' => 25,
                'reference_code' => '888888',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('paypal_order_id', 'PO-DEBT-ADD');

        Http::assertSent(function ($request) use ($advertiser) {
            if (! str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '/v2/checkout/orders')) {
                return false;
            }
            $body = $request->data();

            return ($body['purchase_units'][0]['custom_id'] ?? null) === PaypalCheckoutService::customId(
                PaypalCheckoutService::TYPE_WALLET_DEPOSIT,
                $advertiser->id,
                '888888'
            );
        });
    }

    public function test_advertiser_withdrawal_is_blocked_while_in_debt(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $this->advertiserWallet($advertiser, 80, 17);

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.balance.withdraw'), [
                'amount' => 20,
                'payment_method' => 'paypal',
                'business_name' => 'Acme Media',
                'paypal_email' => 'user@example.com',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'wallet_debt');
    }

    public function test_publisher_withdrawal_ignores_advertiser_debt(): void
    {
        $advertiserRole = Role::firstOrCreate(['name' => 'advertiser']);
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $user->roles()->syncWithoutDetaching([$advertiserRole->id, $publisherRole->id]);

        Wallet::create([
            'user_id' => $user->id,
            'role_id' => $advertiserRole->id,
            'balance' => 0,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'debt_balance' => 17,
            'currency' => 'EUR',
        ]);
        Wallet::create([
            'user_id' => $user->id,
            'role_id' => $publisherRole->id,
            'balance' => 80,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'debt_balance' => 0,
            'currency' => 'EUR',
        ]);

        $this->actingAs($user->fresh())
            ->postJson(route('publisher.withdraw.request'), [
                'amount' => 20,
                'payment_method' => 'paypal',
                'paypal_email' => 'pub@example.com',
                'paypal_email_confirm' => 'pub@example.com',
                'details_confirmed' => '1',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);
    }
}
