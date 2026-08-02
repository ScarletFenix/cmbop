<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class ScheduledOrderCancelRefundTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        Role::firstOrCreate(['name' => 'publisher']);

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function advertiserWallet(User $user): Wallet
    {
        return Wallet::firstOrCreate(
            ['user_id' => $user->id, 'role_id' => Wallet::advertiserRoleId()],
            ['balance' => 0, 'reserved_balance' => 0, 'currency' => 'EUR']
        );
    }

    private function scheduledOrder(User $advertiser, string $paymentMethod, float $amount): Order
    {
        return Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-'.random_int(1000, 9999),
            'subtotal' => $amount,
            'tax' => 0,
            'total_amount' => $amount,
            'payment_method' => $paymentMethod,
            'payment_status' => 'paid',
            'status' => 'pending',
            'publication_mode' => 'scheduled',
            'scheduled_publish_at' => now()->addWeek(),
            'schedule_timezone' => 'UTC',
            'paid_at' => now(),
        ]);
    }

    public function test_cancelling_wallet_scheduled_order_returns_reserved_funds(): void
    {
        $advertiser = $this->advertiser();
        $wallet = $this->advertiserWallet($advertiser);
        $wallet->addBalance(100);

        $amount = 40.0;
        $wallet->refresh()->reserveForOrder($amount);
        $this->assertEqualsWithDelta(60.0, (float) $wallet->fresh()->balance, 0.01);
        $this->assertEqualsWithDelta($amount, (float) $wallet->fresh()->reserved_balance, 0.01);

        $order = $this->scheduledOrder($advertiser, 'wallet', $amount);

        $this->actingAs($advertiser)
            ->post(route('advertiser.scheduled-orders.update', $order), ['action' => 'cancel'])
            ->assertRedirect();

        $wallet->refresh();
        $this->assertEqualsWithDelta(100.0, (float) $wallet->balance, 0.01, 'Reserved funds must return to spendable balance');
        $this->assertEqualsWithDelta(0.0, (float) $wallet->reserved_balance, 0.01);

        $order->refresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertSame('refunded', $order->payment_status);
    }

    public function test_cancelling_card_scheduled_order_credits_wallet(): void
    {
        $advertiser = $this->advertiser();
        $wallet = $this->advertiserWallet($advertiser);

        $order = $this->scheduledOrder($advertiser, 'card', 75.0);

        $this->actingAs($advertiser)
            ->post(route('advertiser.scheduled-orders.update', $order), ['action' => 'cancel'])
            ->assertRedirect();

        $this->assertEqualsWithDelta(75.0, (float) $wallet->fresh()->balance, 0.01);
        $this->assertSame('refunded', $order->fresh()->payment_status);
    }

    public function test_cancelling_twice_does_not_double_refund(): void
    {
        $advertiser = $this->advertiser();
        $wallet = $this->advertiserWallet($advertiser);

        $order = $this->scheduledOrder($advertiser, 'card', 50.0);

        foreach (range(1, 2) as $ignored) {
            $this->actingAs($advertiser)
                ->post(route('advertiser.scheduled-orders.update', $order), ['action' => 'cancel'])
                ->assertRedirect();
        }

        $this->assertEqualsWithDelta(50.0, (float) $wallet->fresh()->balance, 0.01, 'Second cancel must not refund again');
    }

    public function test_cancel_releases_article_back_to_content_library(): void
    {
        $advertiser = $this->advertiser();
        $this->advertiserWallet($advertiser);

        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        $publisher = User::factory()->create(['email_verified_at' => now()]);
        $publisher->roles()->attach($publisherRole->id);

        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Scheduled Site',
            'site_url' => 'https://scheduled.example',
            'domain' => 'scheduled.example',
            'da' => 30,
            'dr' => 30,
            'traffic' => 500,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 30,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Scheduled test site',
            'verified' => true,
            'active' => true,
        ]);

        $order = $this->scheduledOrder($advertiser, 'card', 30.0);
        $item = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://docs.example/scheduled-article',
            'price' => 30,
        ]);

        $submission = $this->createApprovedSubmission($advertiser, $site->id);
        $submission->forceFill([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
        ])->save();

        $this->actingAs($advertiser)
            ->post(route('advertiser.scheduled-orders.update', $order), ['action' => 'cancel'])
            ->assertRedirect();

        $submission->refresh();
        $this->assertNull($submission->order_id);
        $this->assertNull($submission->order_item_id);
    }

    public function test_unpaid_scheduled_order_cancels_without_refund(): void
    {
        $advertiser = $this->advertiser();
        $wallet = $this->advertiserWallet($advertiser);

        $order = $this->scheduledOrder($advertiser, 'card', 25.0);
        $order->update(['payment_status' => 'pending']);

        $this->actingAs($advertiser)
            ->post(route('advertiser.scheduled-orders.update', $order), ['action' => 'cancel'])
            ->assertRedirect();

        $this->assertEqualsWithDelta(0.0, (float) $wallet->fresh()->balance, 0.01);
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_advertiser_cannot_cancel_another_users_scheduled_order(): void
    {
        $owner = $this->advertiser();
        $this->advertiserWallet($owner);
        $intruder = $this->advertiser();

        $order = $this->scheduledOrder($owner, 'card', 60.0);

        $this->actingAs($intruder)
            ->post(route('advertiser.scheduled-orders.update', $order), ['action' => 'cancel'])
            ->assertForbidden();

        $this->assertSame('pending', $order->fresh()->status);
    }
}
