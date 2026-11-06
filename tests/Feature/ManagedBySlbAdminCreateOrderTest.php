<?php

namespace Tests\Feature;

use App\Mail\ManagedOrderConfirmedMail;
use App\Models\Order;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Orders\AdminManagedOrderCreateService;
use App\Support\AdvertiserOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ManagedBySlbAdminCreateOrderTest extends TestCase
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
            'site_name' => 'Create Managed Site',
            'site_url' => 'https://create-managed.example',
            'domain' => 'create-managed.example',
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

    private function fundAdvertiser(User $advertiser, float $amount = 500): Wallet
    {
        $roleId = Wallet::advertiserRoleId();
        $this->assertNotNull($roleId);
        $wallet = Wallet::lockOrCreateForRole($advertiser->id, (int) $roleId, 'EUR');
        $wallet->credit($amount);

        return $wallet->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function createPayload(User $advertiser, Site $site, string $idempotencyKey, array $overrides = []): array
    {
        return array_merge([
            'advertiser_id' => $advertiser->id,
            'site_id' => $site->id,
            'anchor_text' => 'Managed guest post',
            'target_url' => 'https://advertiser.example/landing',
            'content_source' => 'buyer',
            'content_url' => 'https://advertiser.example/article.docx',
            'managed_notes' => 'Created by admin for email fulfilment',
            'idempotency_key' => $idempotencyKey,
            'confirm_charge' => '1',
        ], $overrides);
    }

    public function test_admin_create_charges_wallet_once_and_opens_managed_desk(): void
    {
        Mail::fake();

        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $wallet = $this->fundAdvertiser($advertiser, 500);
        $before = (float) $wallet->balance;

        $service = app(AdminManagedOrderCreateService::class);
        $preview = $service->preview([
            'advertiser_id' => $advertiser->id,
            'site_id' => $site->id,
            'anchor_text' => 'Managed guest post',
            'target_url' => 'https://advertiser.example/landing',
            'content_source' => 'buyer',
            'content_url' => 'https://advertiser.example/article.docx',
        ]);
        $this->assertTrue($preview['sufficient']);
        $this->assertGreaterThan(0, $preview['total']);

        $key = $service->newIdempotencyKey();
        $this->actingAs($admin)
            ->post(route('admin.managed-orders.store'), $this->createPayload($advertiser, $site, $key, [
                'managed_assignee_id' => $admin->id,
            ]))
            ->assertRedirect();

        $order = Order::query()->where('user_id', $advertiser->id)->latest('id')->first();
        $this->assertNotNull($order);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('wallet', $order->payment_method);
        $this->assertSame('pending', $order->status);
        $this->assertStringStartsWith('ADM-MNG-', (string) $order->reference_code);

        $item = $order->items()->first();
        $this->assertNotNull($item);
        $this->assertTrue($item->isManagedBySlb());
        $this->assertSame($admin->id, (int) $item->managed_assignee_id);

        $wallet->refresh();
        $this->assertEqualsWithDelta($before - (float) $preview['total'], (float) $wallet->balance, 0.011);
        $this->assertSame(1, WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('type', WalletTransaction::TYPE_PURCHASE)
            ->where('reference', $order->reference_code)
            ->count());

        $this->actingAs($admin)
            ->get(route('admin.managed-orders.index'))
            ->assertOk()
            ->assertSee($order->order_number);

        Mail::assertQueued(ManagedOrderConfirmedMail::class);
    }

    public function test_insufficient_balance_blocks_creation(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $this->fundAdvertiser($advertiser, 1);

        $service = app(AdminManagedOrderCreateService::class);
        $preview = $service->preview([
            'advertiser_id' => $advertiser->id,
            'site_id' => $site->id,
            'anchor_text' => 'Managed guest post',
            'target_url' => 'https://advertiser.example/landing',
            'content_source' => 'buyer',
            'content_url' => 'https://advertiser.example/article.docx',
        ]);
        $this->assertFalse($preview['sufficient']);

        $key = $service->newIdempotencyKey();
        $this->actingAs($admin)
            ->from(route('admin.managed-orders.create'))
            ->post(route('admin.managed-orders.store'), $this->createPayload($advertiser, $site, $key))
            ->assertRedirect(route('admin.managed-orders.create'))
            ->assertSessionHasErrors('advertiser_id');

        $this->assertSame(0, Order::query()->where('user_id', $advertiser->id)->count());
        $this->assertSame(0, WalletTransaction::query()
            ->where('type', WalletTransaction::TYPE_PURCHASE)
            ->count());
    }

    public function test_double_submit_charges_once(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $wallet = $this->fundAdvertiser($advertiser, 500);
        $before = (float) $wallet->balance;

        $service = app(AdminManagedOrderCreateService::class);
        $preview = $service->preview([
            'advertiser_id' => $advertiser->id,
            'site_id' => $site->id,
            'anchor_text' => 'Managed guest post',
            'target_url' => 'https://advertiser.example/landing',
            'content_source' => 'buyer',
            'content_url' => 'https://advertiser.example/article.docx',
        ]);
        $key = $service->newIdempotencyKey();
        $payload = $this->createPayload($advertiser, $site, $key);

        $this->actingAs($admin)->post(route('admin.managed-orders.store'), $payload)->assertRedirect();
        $this->actingAs($admin)->post(route('admin.managed-orders.store'), $payload)->assertRedirect();

        $this->assertSame(1, Order::query()->where('user_id', $advertiser->id)->count());
        $this->assertSame(1, WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('type', WalletTransaction::TYPE_PURCHASE)
            ->count());

        $wallet->refresh();
        $this->assertEqualsWithDelta($before - (float) $preview['total'], (float) $wallet->balance, 0.011);
    }

    public function test_publisher_cannot_see_admin_created_managed_order(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $this->fundAdvertiser($advertiser, 500);

        $key = app(AdminManagedOrderCreateService::class)->newIdempotencyKey();
        $this->actingAs($admin)
            ->post(route('admin.managed-orders.store'), $this->createPayload($advertiser, $site, $key))
            ->assertRedirect();

        $this->actingAs($publisher)
            ->getJson(route('publisher.orders.data'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(0, 'data');
    }

    public function test_advertiser_sees_admin_created_managed_order_in_my_orders(): void
    {
        Mail::fake();

        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $this->fundAdvertiser($advertiser, 500);

        $key = app(AdminManagedOrderCreateService::class)->newIdempotencyKey();
        $this->actingAs($admin)
            ->post(route('admin.managed-orders.store'), $this->createPayload($advertiser, $site, $key))
            ->assertRedirect();

        $order = Order::query()->where('user_id', $advertiser->id)->with('items')->first();
        $this->assertNotNull($order);
        $meta = AdvertiserOrderStatus::meta($order);
        $this->assertSame('In progress · Managed by SLB', $meta['label']);

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.list'))
            ->assertOk()
            ->assertJsonFragment([
                'order_number' => $order->order_number,
                'status_label' => 'In progress · Managed by SLB',
            ]);
    }

    public function test_confirm_page_shows_balance_and_price(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $this->fundAdvertiser($advertiser, 500);

        $this->actingAs($admin)
            ->post(route('admin.managed-orders.confirm'), [
                'advertiser_id' => $advertiser->id,
                'site_id' => $site->id,
                'anchor_text' => 'Managed guest post',
                'target_url' => 'https://advertiser.example/landing',
                'content_source' => 'buyer',
                'content_url' => 'https://advertiser.example/article.docx',
            ])
            ->assertOk()
            ->assertSee('Confirm wallet charge', false)
            ->assertSee($advertiser->name, false)
            ->assertSee($site->site_name, false)
            ->assertSee('Current wallet balance', false)
            ->assertSee('confirm_charge', false);
    }
}
