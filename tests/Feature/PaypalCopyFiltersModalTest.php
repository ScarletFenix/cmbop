<?php

namespace Tests\Feature;

use App\Mail\AdminStalledOrderAlert;
use App\Mail\AdvertiserOrderStalledNotice;
use App\Mail\DepositReminderMail;
use App\Models\DepositRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaypalCopyFiltersModalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    private function userWithRole(string $roleName): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function siteFor(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'PayPal Copy Site',
            'site_url' => 'https://paypal-copy.example',
            'domain' => 'paypal-copy.example',
            'da' => 30,
            'dr' => 30,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'marketing',
            'price' => 80,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'PayPal leftover copy fixture',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function orderFor(User $advertiser, Site $site, string $method): Order
    {
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'PP-COPY-'.strtoupper($method),
            'reference_code' => 'REF-PP-'.strtoupper($method),
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'status' => 'processing',
            'payment_status' => 'paid',
            'payment_method' => $method,
            'paid_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://paypal-copy.example/article.docx',
            'price' => 80,
            'additional_price' => 0,
            'status' => 'processing',
        ]);

        return $order->fresh('items');
    }

    public function test_stalled_emails_mention_paypal_instead_of_wallet_for_paypal_orders(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $paypal = $this->orderFor($advertiser, $site, 'paypal');
        $wallet = $this->orderFor($advertiser, $site, 'wallet');

        $paypalAdvertiser = (new AdvertiserOrderStalledNotice(
            $advertiser,
            $paypal,
            $paypal->items->first(),
            $site,
            now()->subDays(3),
            72
        ))->render();
        $this->assertStringContainsString('PayPal account that paid', $paypalAdvertiser);
        $this->assertStringNotContainsString('wallet balance', $paypalAdvertiser);

        $walletAdvertiser = (new AdvertiserOrderStalledNotice(
            $advertiser,
            $wallet,
            $wallet->items->first(),
            $site,
            now()->subDays(3),
            72
        ))->render();
        $this->assertStringContainsString('wallet balance', $walletAdvertiser);
        $this->assertStringNotContainsString('PayPal account that paid', $walletAdvertiser);

        $paypalAdmin = (new AdminStalledOrderAlert(
            $paypal,
            $paypal->items->first(),
            $site,
            $publisher,
            3,
            72,
            'publish'
        ))->render();
        $this->assertStringContainsString('PayPal capture returns to the buyer', $paypalAdmin);
        $this->assertStringContainsString('do not credit the wallet again', $paypalAdmin);

        $walletAdmin = (new AdminStalledOrderAlert(
            $wallet,
            $wallet->items->first(),
            $site,
            $publisher,
            3,
            72,
            'publish'
        ))->render();
        $this->assertStringContainsString('wallet balance', $walletAdmin);
        $this->assertStringNotContainsString('PayPal capture returns to the buyer', $walletAdmin);
    }

    public function test_deposit_reminders_mention_paypal(): void
    {
        $advertiser = $this->userWithRole('advertiser');

        $day14 = (new DepositReminderMail($advertiser, DepositReminderMail::STEP_DAY14))->render();
        $this->assertStringContainsString('card or PayPal', $day14);
        $this->assertStringContainsString('bank / Wise / crypto', $day14);

        $day7 = (new DepositReminderMail($advertiser, DepositReminderMail::STEP_DAY7))->render();
        $this->assertStringContainsString('PayPal', $day7);
    }

    public function test_how_it_works_mentions_checkout_paypal(): void
    {
        $this->get('/how-it-works')
            ->assertOk()
            ->assertSee('Pay at checkout with wallet, card, or PayPal', false)
            ->assertSee('PayPal checkout refunds return to the PayPal account that paid', false);
    }

    public function test_admin_payments_filter_includes_paypal_and_filters_rows(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->siteFor($this->userWithRole('publisher'));
        $paypal = $this->orderFor($advertiser, $site, 'paypal');
        $card = $this->orderFor($advertiser, $site, 'card');

        $html = $this->actingAs($admin)
            ->get(route('admin.payments'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('<option value="paypal">PayPal</option>', $html);
        $this->assertStringContainsString("case 'paypal':", $html);

        $filtered = $this->actingAs($admin)
            ->getJson(route('admin.payments.data', ['payment_method' => 'paypal']))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('data');

        $ids = collect($filtered)->pluck('id')->all();
        $this->assertContains($paypal->id, $ids);
        $this->assertNotContains($card->id, $ids);
    }

    public function test_advertiser_orders_filter_includes_paypal_and_filters_rows(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->siteFor($this->userWithRole('publisher'));
        $paypal = $this->orderFor($advertiser, $site, 'paypal');
        $card = $this->orderFor($advertiser, $site, 'card');

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.orders'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('value="paypal"', $html);
        $this->assertStringContainsString('PayPal', $html);

        $orders = $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.list', ['payment_method' => 'paypal']))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('orders');

        $ids = collect($orders)->pluck('id')->all();
        $this->assertSame([$paypal->id], $ids);
        $this->assertNotContains($card->id, $ids);
    }

    public function test_admin_deposit_show_includes_paypal_ids_and_refund_payload(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $deposit = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => '888777',
            'amount' => 25,
            'payment_method' => 'paypal',
            'status' => 'refunded',
            'paypal_order_id' => 'PO-MODAL-1',
            'paypal_capture_id' => 'CAP-MODAL-1',
            'paypal_response' => [
                'refund' => [
                    'id' => 'RF-MODAL-1',
                    'amount' => 25,
                    'debited' => 8,
                    'debt_created' => 17,
                ],
            ],
            'approved_at' => now(),
            'paid_at' => now(),
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.deposits.show', $deposit->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('deposit.paypal_order_id', 'PO-MODAL-1')
            ->assertJsonPath('deposit.paypal_capture_id', 'CAP-MODAL-1')
            ->assertJsonPath('deposit.paypal_response.refund.id', 'RF-MODAL-1')
            ->assertJsonPath('deposit.paypal_response.refund.debt_created', 17)
            ->assertJsonPath('can_refund_paypal', false);

        $refundable = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => '888778',
            'amount' => 40,
            'payment_method' => 'paypal',
            'status' => 'completed',
            'paypal_order_id' => 'PO-MODAL-2',
            'paypal_capture_id' => 'CAP-MODAL-2',
            'approved_at' => now(),
            'paid_at' => now(),
        ]);

        config([
            'services.paypal.enabled' => true,
            'services.paypal.client_id' => 'paypal-client-test',
            'services.paypal.secret' => 'paypal-secret-test',
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.deposits.show', $refundable->id))
            ->assertOk()
            ->assertJsonPath('can_refund_paypal', true);

        $page = $this->actingAs($admin)
            ->get(route('admin.deposits'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('id="depositModalTitle"', $page);
        $this->assertStringContainsString('paypalDepositFields', $page);
        $this->assertStringContainsString('Deposit details', $page);
        $this->assertStringContainsString('refundPaypalDeposit', $page);
        $this->assertStringContainsString('Refund PayPal capture', $page);
    }
}
