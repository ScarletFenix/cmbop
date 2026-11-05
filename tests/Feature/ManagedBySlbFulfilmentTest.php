<?php

namespace Tests\Feature;

use App\Mail\ManagedOrderCompletedMail;
use App\Mail\ManagedOrderConfirmedMail;
use App\Mail\ManagedOrderLiveUrlMail;
use App\Mail\NewChatMessageNotification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Orders\ManagedFulfilmentService;
use App\Support\AdvertiserOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ManagedBySlbFulfilmentTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
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
            'site_name' => 'Managed Site',
            'site_url' => 'https://managed-site.example',
            'domain' => 'managed-site.example',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 100,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Test site',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function paidOrder(User $advertiser, Site $site, array $itemAttrs = []): Order
    {
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-MNG-'.uniqid(),
            'reference_code' => 'REF-MNG-'.uniqid(),
            'subtotal' => 115,
            'tax' => 0,
            'total_amount' => 115,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'pending',
            'paid_at' => now(),
        ]);

        OrderItem::create(array_merge([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 115,
            'publisher_price' => 100,
            'content_link' => 'https://example.com/article.docx',
            'modification_requested' => 'no',
            'fulfilment_source' => 'publisher',
        ], $itemAttrs));

        return $order->fresh('items');
    }

    public function test_migration_adds_fulfilment_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('order_items', 'fulfilment_source'));
        $this->assertTrue(Schema::hasColumn('order_items', 'managed_assignee_id'));
        $this->assertTrue(Schema::hasColumn('order_items', 'managed_internal_status'));
        $this->assertTrue(Schema::hasColumn('order_items', 'managed_notes'));
        $this->assertTrue(Schema::hasColumn('order_items', 'managed_marked_at'));
        $this->assertTrue(Schema::hasColumn('order_items', 'managed_completed_by'));
    }

    public function test_publisher_cannot_see_managed_order_in_tasks(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $order = $this->paidOrder($advertiser, $site);
        $item = $order->items->first();

        app(ManagedFulfilmentService::class)->markManaged($item, $admin, null, null, false);

        $this->actingAs($publisher)
            ->getJson(route('publisher.orders.data'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(0, 'data');
    }

    public function test_managed_complete_does_not_credit_publisher_wallet(): void
    {
        Mail::fake();

        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $order = $this->paidOrder($advertiser, $site, [
            'status' => null,
        ]);
        $item = $order->items->first();

        $publisherRoleId = Wallet::publisherRoleId();
        $this->assertNotNull($publisherRoleId);
        $wallet = Wallet::lockOrCreateForRole($publisher->id, (int) $publisherRoleId, 'EUR');
        $before = (float) $wallet->balance;

        $managed = app(ManagedFulfilmentService::class);
        $managed->markManaged($item, $admin, $admin->id, 'Email with publisher', false);
        $managed->complete($item->fresh(), $admin, 'https://managed-site.example/live-post');

        $wallet->refresh();
        $this->assertSame($before, (float) $wallet->balance);
        $this->assertFalse(
            WalletTransaction::query()
                ->where('reference', 'ORDER-ITEM-'.$item->id)
                ->where('type', WalletTransaction::TYPE_TRANSFER_IN)
                ->exists()
        );

        $order->refresh();
        $this->assertSame('completed', $order->status);
        $this->assertSame('Completed · Managed by SLB', AdvertiserOrderStatus::meta($order->fresh('items'))['label']);

        Mail::assertQueued(ManagedOrderCompletedMail::class);
    }

    public function test_advertiser_sees_managed_label_and_live_url(): void
    {
        Mail::fake();

        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $order = $this->paidOrder($advertiser, $site);
        $item = $order->items->first();

        $managed = app(ManagedFulfilmentService::class);
        $managed->markManaged($item, $admin, null, null, true);
        $managed->attachLiveUrl($item->fresh(), 'https://managed-site.example/article', $admin);

        $meta = AdvertiserOrderStatus::meta($order->fresh('items'));
        $this->assertSame('In progress · Managed by SLB', $meta['label']);
        $this->assertStringContainsString('our team will handle it', $meta['next']);

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.list'))
            ->assertOk()
            ->assertJsonFragment(['status_label' => 'In progress · Managed by SLB']);

        Mail::assertQueued(ManagedOrderConfirmedMail::class);
        Mail::assertQueued(ManagedOrderLiveUrlMail::class);
    }

    public function test_chat_on_managed_order_is_not_delivered_to_publisher(): void
    {
        Mail::fake();

        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $order = $this->paidOrder($advertiser, $site);
        $item = $order->items->first();

        app(ManagedFulfilmentService::class)->markManaged($item, $admin, $admin->id, null, false);

        $this->actingAs($advertiser)
            ->postJson(route('chat.send', $order->id), [
                'message' => 'Please use the brief we emailed.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('order_chat_messages', [
            'order_id' => $order->id,
            'sender_type' => 'advertiser',
            'message' => 'Please use the brief we emailed.',
        ]);

        Mail::assertQueued(NewChatMessageNotification::class, function (NewChatMessageNotification $mail) use ($admin, $publisher) {
            return $mail->hasTo($admin->email) && ! $mail->hasTo($publisher->email);
        });

        $this->actingAs($publisher)
            ->getJson(route('chat.messages', $order->id))
            ->assertStatus(403);
    }

    public function test_admin_desk_lists_and_completes_managed_order(): void
    {
        Mail::fake();

        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $order = $this->paidOrder($advertiser, $site);
        $item = $order->items->first();

        $this->actingAs($admin)
            ->post(route('admin.managed-orders.mark', $order), [
                'managed_assignee_id' => $admin->id,
                'managed_notes' => 'Off-platform email thread',
            ])
            ->assertRedirect(route('admin.managed-orders.index'));

        $item->refresh();
        $this->assertTrue($item->isManagedBySlb());
        $this->assertSame($admin->id, (int) $item->managed_assignee_id);

        $this->actingAs($admin)
            ->get(route('admin.managed-orders.index'))
            ->assertOk()
            ->assertSee($order->order_number)
            ->assertSee('Managed by SLB');

        $this->actingAs($admin)
            ->post(route('admin.managed-orders.complete', $item), [
                'live_url' => 'https://managed-site.example/done',
            ])
            ->assertRedirect(route('admin.managed-orders.show', $item));

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('https://managed-site.example/done', $item->fresh()->live_url);
    }

    public function test_approve_path_skips_publisher_credit_for_managed_lines(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $order = $this->paidOrder($advertiser, $site);
        $item = $order->items->first();

        app(ManagedFulfilmentService::class)->markManaged($item, $admin, null, null, false);
        $item->update([
            'live_url' => 'https://managed-site.example/review',
            'live_url_submitted_at' => now(),
            'modification_requested' => 'no',
        ]);
        $order->update(['status' => 'review']);

        $publisherRoleId = Wallet::publisherRoleId();
        $wallet = Wallet::lockOrCreateForRole($publisher->id, (int) $publisherRoleId, 'EUR');
        $before = (float) $wallet->balance;

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.orders.approve', $order->id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $wallet->refresh();
        $this->assertSame($before, (float) $wallet->balance);
        $this->assertSame('completed', $order->fresh()->status);
    }
}
