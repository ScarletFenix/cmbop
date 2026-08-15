<?php

namespace Tests\Feature;

use App\Mail\ModificationRequested;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdvertiserApproveMultiItemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_approve_refuses_when_a_sibling_line_has_no_live_url(): void
    {
        [$advertiser, $firstPublisher, $secondPublisher, $order] = $this->multiItemReviewOrder(
            firstLive: true,
            secondLive: false,
        );

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.orders.approve', $order->id))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame('review', $order->fresh()->status);
        $this->assertEquals(0.0, (float) Wallet::where('user_id', $firstPublisher->id)->value('balance'));
        $this->assertEquals(0.0, (float) Wallet::where('user_id', $secondPublisher->id)->value('balance'));
        $this->assertEquals(160.0, (float) Wallet::where('user_id', $advertiser->id)->value('reserved_balance'));
    }

    public function test_approve_pays_each_ready_line_once(): void
    {
        [$advertiser, $firstPublisher, $secondPublisher, $order] = $this->multiItemReviewOrder(
            firstLive: true,
            secondLive: true,
        );

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.orders.approve', $order->id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertEquals(70.0, (float) Wallet::where('user_id', $firstPublisher->id)->value('balance'));
        $this->assertEquals(70.0, (float) Wallet::where('user_id', $secondPublisher->id)->value('balance'));
        $this->assertEquals(0.0, (float) Wallet::where('user_id', $advertiser->id)->value('reserved_balance'));
    }

    public function test_approve_does_not_repay_a_line_already_auto_approved(): void
    {
        [$advertiser, $firstPublisher, $secondPublisher, $order] = $this->multiItemReviewOrder(
            firstLive: true,
            secondLive: true,
        );

        $first = $order->items->first();
        $first->update([
            'auto_approve_triggered' => true,
            'auto_approve_at' => now()->subHour(),
            'completed_at' => now()->subHour(),
            'publisher_status' => 'completed',
        ]);

        Wallet::where('user_id', $firstPublisher->id)->update(['balance' => 70]);

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.orders.approve', $order->id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertEquals(70.0, (float) Wallet::where('user_id', $firstPublisher->id)->value('balance'));
        $this->assertEquals(70.0, (float) Wallet::where('user_id', $secondPublisher->id)->value('balance'));
        $this->assertEquals(0.0, (float) Wallet::where('user_id', $advertiser->id)->value('reserved_balance'));
    }

    public function test_request_modification_does_not_rewind_a_paid_line(): void
    {
        [$advertiser, $firstPublisher, , $order] = $this->multiItemReviewOrder(
            firstLive: true,
            secondLive: true,
        );

        $first = $order->items->first();
        $second = $order->items->last();
        $first->update([
            'auto_approve_triggered' => true,
            'completed_at' => now()->subHour(),
            'publisher_status' => 'completed',
        ]);
        Wallet::where('user_id', $firstPublisher->id)->update(['balance' => 70]);

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.order.modification', $order->id), [
                'reason' => 'Please fix the second article heading and republish.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $first->refresh();
        $second->refresh();

        $this->assertTrue((bool) $first->auto_approve_triggered);
        $this->assertSame('completed', $first->publisher_status);
        $this->assertNotSame('yes', $first->modification_requested);
        $this->assertSame('yes', $second->modification_requested);
        $this->assertFalse((bool) $second->auto_approve_triggered);
        $this->assertEquals(70.0, (float) Wallet::where('user_id', $firstPublisher->id)->value('balance'));
    }

    public function test_request_modification_is_blocked_when_payment_is_not_paid(): void
    {
        [$advertiser, , , $order] = $this->multiItemReviewOrder(
            firstLive: true,
            secondLive: true,
        );
        $order->update([
            'payment_status' => 'pending',
            'paid_at' => null,
        ]);

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.order.modification', $order->id), [
                'reason' => 'Please fix the heading even though this checkout is unpaid.',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame('review', $order->fresh()->status);
        $this->assertFalse($order->items->contains(fn ($line) => $line->fresh()->modification_requested === 'yes'));
    }

    public function test_request_modification_emails_every_unpaid_publisher(): void
    {
        [$advertiser, $firstPublisher, $secondPublisher, $order] = $this->multiItemReviewOrder(
            firstLive: true,
            secondLive: true,
        );

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.order.modification', $order->id), [
                'reason' => 'Please fix the heading on both placements and republish.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        Mail::assertQueued(ModificationRequested::class, 2);
        Mail::assertQueued(
            ModificationRequested::class,
            fn (ModificationRequested $mail) => $mail->hasTo($firstPublisher->email)
        );
        Mail::assertQueued(
            ModificationRequested::class,
            fn (ModificationRequested $mail) => $mail->hasTo($secondPublisher->email)
        );
    }

    public function test_request_modification_skips_email_for_already_paid_publisher(): void
    {
        [$advertiser, $firstPublisher, $secondPublisher, $order] = $this->multiItemReviewOrder(
            firstLive: true,
            secondLive: true,
        );

        $order->items->first()->update([
            'auto_approve_triggered' => true,
            'completed_at' => now()->subHour(),
            'publisher_status' => 'completed',
        ]);
        Wallet::where('user_id', $firstPublisher->id)->update(['balance' => 70]);

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.order.modification', $order->id), [
                'reason' => 'Please fix only the unpaid placement and republish.',
            ])
            ->assertOk();

        Mail::assertQueued(ModificationRequested::class, 1);
        Mail::assertQueued(
            ModificationRequested::class,
            fn (ModificationRequested $mail) => $mail->hasTo($secondPublisher->email)
        );
        Mail::assertNotQueued(
            ModificationRequested::class,
            fn (ModificationRequested $mail) => $mail->hasTo($firstPublisher->email)
        );
    }

    /**
     * @return array{0: User, 1: User, 2: User, 3: Order}
     */
    private function multiItemReviewOrder(bool $firstLive, bool $secondLive): array
    {
        $advertiser = $this->userWithRole('advertiser');
        $firstPublisher = $this->userWithRole('publisher');
        $secondPublisher = $this->userWithRole('publisher');

        Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => $advertiser->active_role_id,
            'balance' => 0,
            'reserved_balance' => 160,
        ]);
        Wallet::create([
            'user_id' => $firstPublisher->id,
            'role_id' => $firstPublisher->active_role_id,
            'balance' => 0,
            'reserved_balance' => 0,
        ]);
        Wallet::create([
            'user_id' => $secondPublisher->id,
            'role_id' => $secondPublisher->active_role_id,
            'balance' => 0,
            'reserved_balance' => 0,
        ]);

        $firstSite = $this->makeSite($firstPublisher, 'first-approve.example', 80);
        $secondSite = $this->makeSite($secondPublisher, 'second-approve.example', 80);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-MULTI-'.uniqid(),
            'reference_code' => 'REF-MULTI-'.uniqid(),
            'subtotal' => 160,
            'tax' => 0,
            'total_amount' => 160,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'review',
            'paid_at' => now(),
        ]);

        $this->makeItem($order, $firstSite, 80, $firstLive);
        $this->makeItem($order, $secondSite, 80, $secondLive);

        return [$advertiser, $firstPublisher, $secondPublisher, $order->fresh('items')];
    }

    private function userWithRole(string $role): User
    {
        $roleModel = Role::firstOrCreate(['name' => $role]);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $roleModel->id,
        ]);
        $user->roles()->attach($roleModel->id);

        return $user->fresh();
    }

    private function makeSite(User $publisher, string $domain, float $price): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => $domain,
            'site_url' => 'https://'.$domain,
            'domain' => $domain,
            'da' => 30,
            'dr' => 35,
            'traffic' => 4000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => $price,
            'turnaround_time' => '3days',
            'publication_time' => '5 days',
            'link_type' => 'dofollow',
            'description' => 'Test site',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function makeItem(Order $order, Site $site, float $price, bool $hasLiveUrl): OrderItem
    {
        return OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => $price,
            'publisher_price' => 70,
            'content_link' => 'https://example.com/article.docx',
            'accepted_at' => now()->subDay(),
            'publisher_status' => 'accepted',
            'live_url' => $hasLiveUrl ? 'https://'.$site->domain.'/live' : null,
            'live_url_submitted_at' => $hasLiveUrl ? now()->subHours(2) : null,
            'modification_requested' => 'no',
        ]);
    }
}
