<?php

namespace Tests\Feature;

use App\Mail\DepositApproved;
use App\Models\DepositRequest;
use App\Models\InAppNotification;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Services\PaypalCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AddFundsPaypalTest extends TestCase
{
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user->fresh();
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

    /**
     * @param  array<string, mixed>  $capture
     */
    private function fakePaypal(string $orderId, array $capture = []): void
    {
        Http::fake(function ($request) use ($orderId, $capture) {
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

            $userId = (string) ($capture['user_id'] ?? '0');
            $ref = (string) ($capture['reference_code'] ?? '888888');
            $amount = (string) ($capture['amount'] ?? '25.00');
            $completed = [
                'id' => $orderId,
                'status' => 'COMPLETED',
                'purchase_units' => [[
                    'custom_id' => PaypalCheckoutService::customId(
                        PaypalCheckoutService::TYPE_WALLET_DEPOSIT,
                        $userId,
                        $ref
                    ),
                    'payments' => [
                        'captures' => [[
                            'id' => (string) ($capture['id'] ?? 'CAP-'.$orderId),
                            'status' => 'COMPLETED',
                            'amount' => ['currency_code' => 'EUR', 'value' => $amount],
                        ]],
                    ],
                ]],
            ];

            if (str_contains($url, '/v2/checkout/orders/'.$orderId.'/capture')
                || str_contains($url, '/v2/checkout/orders/'.$orderId)) {
                return Http::response($completed, 201);
            }

            return Http::response(['name' => 'RESOURCE_NOT_FOUND'], 404);
        });
    }

    public function test_add_funds_page_shows_paypal_tile_when_configured(): void
    {
        $this->enablePaypal();
        $html = $this->actingAs($this->advertiser())
            ->get(route('advertiser.add-funds'))
            ->assertOk()
            ->assertSee('data-method="paypal"', false)
            ->assertDontSee('PayPal coming soon', false)
            ->getContent();

        $this->assertStringContainsString('createPaypal', $html);
        $this->assertStringContainsString('paypalReady: true', $html);
    }

    public function test_create_paypal_deposit_returns_approve_url(): void
    {
        $this->enablePaypal();
        $this->fakePaypal('PO-DEP-CREATE');
        $user = $this->advertiser();

        $this->actingAs($user)
            ->postJson(route('advertiser.add-funds.paypal.create'), [
                'amount' => 25,
                'reference_code' => '888888',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('paypal_order_id', 'PO-DEP-CREATE')
            ->assertJsonPath('checkout_url', 'https://www.sandbox.paypal.com/checkoutnow?token=PO-DEP-CREATE');

        Http::assertSent(function ($request) use ($user) {
            if (! str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '/v2/checkout/orders')) {
                return false;
            }
            $body = $request->data();

            return ($body['intent'] ?? null) === 'CAPTURE'
                && ($body['purchase_units'][0]['custom_id'] ?? null) === PaypalCheckoutService::customId(
                    PaypalCheckoutService::TYPE_WALLET_DEPOSIT,
                    $user->id,
                    '888888'
                )
                && ($body['purchase_units'][0]['amount']['value'] ?? null) === '25.00';
        });
    }

    public function test_paypal_deposit_return_credits_wallet_once(): void
    {
        Mail::fake();
        $this->enablePaypal();
        $user = $this->advertiser();
        $this->fakePaypal('PO-DEP-RET', [
            'id' => 'CAP-DEP-RET',
            'user_id' => (string) $user->id,
            'reference_code' => '888888',
            'amount' => '25.00',
        ]);

        Wallet::create([
            'user_id' => $user->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 1,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $this->actingAs($user)
            ->get(route('advertiser.add-funds.paypal.return', [
                'ref' => '888888',
                'token' => 'PO-DEP-RET',
            ]))
            ->assertRedirect(route('advertiser.add-funds'));

        $this->actingAs($user)
            ->get(route('advertiser.add-funds.paypal.return', [
                'ref' => '888888',
                'token' => 'PO-DEP-RET',
            ]))
            ->assertRedirect(route('advertiser.add-funds'));

        $this->assertSame(1, DepositRequest::query()->where('paypal_capture_id', 'CAP-DEP-RET')->count());
        $this->assertEqualsWithDelta(26.0, (float) Wallet::query()
            ->where('user_id', $user->id)
            ->where('role_id', Wallet::advertiserRoleId())
            ->value('balance'), 0.01);

        Mail::assertQueued(DepositApproved::class, function (DepositApproved $mail) use ($user) {
            return (int) $mail->deposit->user_id === (int) $user->id
                && $mail->deposit->payment_method === 'paypal'
                && $mail->notificationType === 'deposit_approved';
        });
        Mail::assertQueued(DepositApproved::class, 1);

        $this->assertTrue(InAppNotification::query()
            ->where('user_id', $user->id)
            ->where('title', 'Wallet topped up — €25.00')
            ->exists());
    }

    public function test_create_paypal_deposit_fails_closed_when_disabled(): void
    {
        config([
            'services.paypal.enabled' => false,
            'services.paypal.client_id' => 'paypal-client-test',
            'services.paypal.secret' => 'paypal-secret-test',
        ]);
        Http::fake();

        $this->actingAs($this->advertiser())
            ->postJson(route('advertiser.add-funds.paypal.create'), [
                'amount' => 25,
                'reference_code' => '888888',
            ])
            ->assertStatus(503)
            ->assertJsonPath('success', false);

        Http::assertNothingSent();
    }
}
