<?php

namespace Tests\Feature;

use App\Models\ContentSubmission;
use App\Models\Order;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CheckoutIntentService;
use App\Services\StripeCustomerService;
use App\Services\Wallet\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class SavedCardOrderCheckoutTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'content_moderation.enabled' => false,
            'services.stripe.secret' => 'sk_test_fake_key_for_unit_tests',
            'services.stripe.key' => 'pk_test_fake_key_for_unit_tests',
        ]);
    }

    public function test_saved_card_charges_and_creates_paid_orders(): void
    {
        [$advertiser, $site, $submission] = $this->readyCheckout();

        $this->mock(StripeCustomerService::class, function ($mock) {
            $mock->shouldReceive('payWithSavedCard')
                ->once()
                ->andReturnUsing(function ($user, $paymentMethodId, $amountCents) {
                    $this->assertSame('pm_test_visa', $paymentMethodId);
                    $this->assertGreaterThan(0, $amountCents);

                    return [
                        'status' => 'succeeded',
                        'payment_intent_id' => 'pi_saved_ok',
                        'client_secret' => 'pi_saved_ok_secret',
                        'amount_received' => $amountCents,
                    ];
                });
            $mock->shouldReceive('createCheckoutSession')->never();
        });

        $this->actingAs($advertiser)
            ->withSession($this->cartSession($site))
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'card',
                'payment_method_id' => 'pm_test_visa',
                'reference_code' => 'SAVED1',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$submission->id],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('reference_code', 'SAVED1')
            ->assertJsonMissing(['requires_payment' => true])
            ->assertJsonMissing(['checkout_url']);

        $order = Order::where('reference_code', 'SAVED1')->first();
        $this->assertNotNull($order);
        $this->assertSame('card', $order->payment_method);
        $this->assertSame('paid', $order->payment_status);
        $this->assertNull(Cache::get('pending_card_checkout:SAVED1'));
    }

    public function test_saved_card_3ds_returns_client_secret_without_orders(): void
    {
        [$advertiser, $site, $submission] = $this->readyCheckout();

        $this->mock(StripeCustomerService::class, function ($mock) {
            $mock->shouldReceive('payWithSavedCard')->once()->andReturn([
                'status' => 'requires_action',
                'payment_intent_id' => 'pi_saved_3ds',
                'client_secret' => 'pi_saved_3ds_secret',
            ]);
            $mock->shouldReceive('createCheckoutSession')->never();
        });

        $this->actingAs($advertiser)
            ->withSession($this->cartSession($site))
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'card',
                'payment_method_id' => 'pm_test_visa',
                'reference_code' => 'SAVED3DS',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$submission->id],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('requires_action', true)
            ->assertJsonPath('client_secret', 'pi_saved_3ds_secret')
            ->assertJsonPath('stripe_key', 'pk_test_fake_key_for_unit_tests');

        $this->assertSame(0, Order::where('reference_code', 'SAVED3DS')->count());
        $this->assertNotNull(Cache::get('pending_card_checkout:SAVED3DS'));
    }

    public function test_saved_card_failure_releases_pending_checkout(): void
    {
        [$advertiser, $site, $submission] = $this->readyCheckout();

        $this->mock(StripeCustomerService::class, function ($mock) {
            $mock->shouldReceive('payWithSavedCard')
                ->once()
                ->andThrow(new \RuntimeException('This card does not belong to your account.'));
            $mock->shouldReceive('createCheckoutSession')->never();
        });

        $this->actingAs($advertiser)
            ->withSession($this->cartSession($site))
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'card',
                'payment_method_id' => 'pm_test_visa',
                'reference_code' => 'SAVEDFAIL',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$submission->id],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(0, Order::where('reference_code', 'SAVEDFAIL')->count());
        $this->assertNull(Cache::get('pending_card_checkout:SAVEDFAIL'));
    }

    public function test_saved_card_keeps_leftover_hold_when_ledger_throws_after_pay(): void
    {
        Mail::fake();
        [$advertiser, $site, $submission] = $this->readyCheckout();
        $publisher = User::query()->findOrFail($site->publisher_id);
        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 20,
            'reserved_balance' => 0,
            'bonus_balance' => 20,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $this->mock(StripeCustomerService::class, function ($mock) {
            $mock->shouldReceive('payWithSavedCard')
                ->once()
                ->andReturnUsing(function ($user, $paymentMethodId, $amountCents) {
                    $this->assertSame('pm_test_visa', $paymentMethodId);
                    $this->assertGreaterThan(0, $amountCents);

                    return [
                        'status' => 'succeeded',
                        'payment_intent_id' => 'pi_saved_ledger_fail',
                        'client_secret' => 'pi_saved_ledger_fail_secret',
                        'amount_received' => $amountCents,
                    ];
                });
            $mock->shouldReceive('createCheckoutSession')->never();
        });
        $this->partialMock(WalletLedgerService::class, function ($mock) {
            $mock->shouldReceive('recordPurchaseOnce')
                ->once()
                ->andThrow(new \RuntimeException('ledger schema mismatch'));
        });

        $this->actingAs($advertiser)
            ->withSession($this->cartSession($site))
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'card',
                'payment_method_id' => 'pm_test_visa',
                'reference_code' => 'SAVEDHOLD',
                'publication_mode' => 'immediate',
                'use_bonus' => '1',
                'content_submissions' => [
                    $site->id => [$submission->id],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('reference_code', 'SAVEDHOLD');

        $order = Order::where('reference_code', 'SAVEDHOLD')->first();
        $this->assertNotNull($order);
        $this->assertSame('card', $order->payment_method);
        $this->assertSame('paid', $order->payment_status);
        $this->assertEqualsWithDelta(
            20.0,
            app(CheckoutIntentService::class)->heldBonus($advertiser->id, 'SAVEDHOLD'),
            0.01
        );

        $wallet->refresh();
        $wallet->update([
            'balance' => round((float) $wallet->balance + 20, 2),
            'bonus_balance' => round((float) $wallet->bonus_balance + 20, 2),
        ]);
        $wallet->reserveBonusOnly(20);
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, 'OTHER-OPEN-SAVED-1', 20);

        $this->actingAs($publisher)
            ->postJson(route('publisher.orders.reject', $order->items->first()->id), [
                'reason' => 'The topic does not fit our editorial guidelines.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $wallet->refresh();
        $cashRefunded = round((float) $order->total_amount - 20.0, 2);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta($cashRefunded, $wallet->withdrawableBalance(), 0.01);
        $this->assertEqualsWithDelta(
            0.0,
            app(CheckoutIntentService::class)->heldBonus($advertiser->id, 'SAVEDHOLD'),
            0.01
        );
        $this->assertEqualsWithDelta(
            20.0,
            app(CheckoutIntentService::class)->heldBonus($advertiser->id, 'OTHER-OPEN-SAVED-1'),
            0.01
        );
    }

    /**
     * @return array{0: User, 1: Site, 2: ContentSubmission}
     */
    private function readyCheckout(): array
    {
        $advertiserRole = Role::firstOrCreate(['name' => 'advertiser']);
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);

        $advertiser = User::factory()->create(['email_verified_at' => now()]);
        $advertiser->roles()->attach($advertiserRole->id);
        $advertiser->active_role_id = $advertiserRole->id;
        $advertiser->save();

        $publisher = User::factory()->create(['email_verified_at' => now()]);
        $publisher->roles()->attach($publisherRole->id);

        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Saved Card Site',
            'site_url' => 'https://saved-card.example',
            'domain' => 'saved-card.example',
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
            'description' => 'Test site for saved card checkout',
            'verified' => true,
            'active' => true,
        ]);

        $submission = $this->createApprovedSubmission($advertiser->fresh(), $site->id);

        return [$advertiser->fresh(), $site, $submission];
    }

    /**
     * @return array<string, mixed>
     */
    private function cartSession(Site $site): array
    {
        return [
            'cart' => [[
                'id' => $site->id,
                'name' => $site->site_name,
                'quantity' => 1,
                'price' => 9999,
                'sensitive_type' => null,
            ]],
        ];
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
