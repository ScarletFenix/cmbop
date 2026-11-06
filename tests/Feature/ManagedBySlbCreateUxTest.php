<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderPack;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Orders\AdminManagedOrderCreateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ManagedBySlbCreateUxTest extends TestCase
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

    private function siteFor(User $publisher, array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Managed UX Site',
            'site_url' => 'https://managed-ux.example',
            'domain' => 'managed-ux.example',
            'da' => 42,
            'dr' => 38,
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
        ], $overrides));
    }

    private function fundAdvertiser(User $advertiser, float $amount = 500): Wallet
    {
        $roleId = Wallet::advertiserRoleId();
        $this->assertNotNull($roleId);
        $wallet = Wallet::lockOrCreateForRole($advertiser->id, (int) $roleId, 'EUR');
        $wallet->credit($amount);

        return $wallet->fresh();
    }

    public function test_create_page_uses_typeahead_and_drops_side_search_panels(): void
    {
        $admin = $this->userWithRole('admin');

        $html = $this->actingAs($admin)
            ->get(route('admin.managed-orders.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-admin-select-search-url', $html);
        $this->assertStringContainsString('managed-orders/advertisers-search', $html);
        $this->assertStringContainsString('managed-orders/sites-search', $html);
        $this->assertStringContainsString('Price summary', $html);
        $this->assertStringNotContainsString('Find advertiser', $html);
        $this->assertStringNotContainsString('Find site', $html);
        $this->assertStringNotContainsString('reload options', $html);
    }

    public function test_advertiser_and_site_search_endpoints(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $advertiser->forceFill([
            'name' => 'Ada Advertiser',
            'email' => 'ada-managed-search@example.com',
        ])->save();
        $this->fundAdvertiser($advertiser, 250);

        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher, [
            'site_name' => 'Alpha Search Site',
            'domain' => 'alpha-search.example',
            'site_url' => 'https://alpha-search.example',
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.managed-orders.advertisers-search', ['q' => 'Ada']))
            ->assertOk()
            ->assertJsonPath('options.0.value', (string) $advertiser->id)
            ->assertJsonFragment(['balance' => 250.0]);

        $this->actingAs($admin)
            ->getJson(route('admin.managed-orders.advertisers-search', ['q' => (string) $advertiser->id]))
            ->assertOk()
            ->assertJsonPath('options.0.value', (string) $advertiser->id);

        $this->actingAs($admin)
            ->getJson(route('admin.managed-orders.sites-search', ['q' => 'alpha-search']))
            ->assertOk()
            ->assertJsonPath('options.0.value', (string) $site->id)
            ->assertJsonPath('options.0.data.da', 42)
            ->assertJsonPath('options.0.data.max_links', 3);

        $this->actingAs($admin)
            ->getJson(route('admin.managed-orders.sites-search', ['q' => (string) $site->id]))
            ->assertOk()
            ->assertJsonPath('options.0.value', (string) $site->id);
    }

    public function test_quote_blocks_review_when_balance_insufficient(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $this->fundAdvertiser($advertiser, 1);

        $this->actingAs($admin)
            ->postJson(route('admin.managed-orders.quote'), [
                'advertiser_id' => $advertiser->id,
                'sites' => [[
                    'site_id' => $site->id,
                    'quantity' => 1,
                    'content_source' => 'buyer',
                    'content_url' => 'https://advertiser.example/article.docx',
                    'links' => [[
                        'anchor' => 'Guest post',
                        'url' => 'https://advertiser.example/landing',
                    ]],
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('quote.sufficient', false);

        $key = app(AdminManagedOrderCreateService::class)->newIdempotencyKey();
        $this->actingAs($admin)
            ->from(route('admin.managed-orders.create'))
            ->post(route('admin.managed-orders.store'), [
                'advertiser_id' => $advertiser->id,
                'sites' => [[
                    'site_id' => $site->id,
                    'quantity' => 1,
                    'content_source' => 'buyer',
                    'content_url' => 'https://advertiser.example/article.docx',
                    'links' => [[
                        'anchor' => 'Guest post',
                        'url' => 'https://advertiser.example/landing',
                    ]],
                ]],
                'idempotency_key' => $key,
                'confirm_charge' => '1',
            ])
            ->assertRedirect(route('admin.managed-orders.create'))
            ->assertSessionHasErrors('advertiser_id');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_multi_link_cap_rejects_over_limit(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher, ['link_type' => 'nofollow']);
        $this->fundAdvertiser($advertiser, 500);

        $service = app(AdminManagedOrderCreateService::class);
        $this->assertSame(1, $service->maxLinkPairsForSite($site));

        try {
            $service->quote([
                'advertiser_id' => $advertiser->id,
                'sites' => [[
                    'site_id' => $site->id,
                    'quantity' => 1,
                    'content_source' => 'buyer',
                    'content_url' => 'https://advertiser.example/article.docx',
                    'links' => [
                        ['anchor' => 'One', 'url' => 'https://advertiser.example/a'],
                        ['anchor' => 'Two', 'url' => 'https://advertiser.example/b'],
                    ],
                ]],
            ]);
            $this->fail('Expected ValidationException for link cap');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('sites.0.links', $e->errors());
        }
    }

    public function test_multi_site_pack_pricing_charges_wallet_once(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $siteA = $this->siteFor($publisher, [
            'site_name' => 'Pack Site A',
            'domain' => 'pack-a.example',
            'site_url' => 'https://pack-a.example',
            'price' => 100,
            'bulk_discount_enabled' => 1,
            'bulk_discount_percent' => 10,
            'bulk_discount_percent_5' => 15,
            'bulk_discount_percent_10' => 25,
        ]);
        $siteB = $this->siteFor($publisher, [
            'site_name' => 'Extra Site B',
            'domain' => 'pack-b.example',
            'site_url' => 'https://pack-b.example',
            'price' => 80,
        ]);
        $wallet = $this->fundAdvertiser($advertiser, 2000);
        $before = (float) $wallet->balance;

        $service = app(AdminManagedOrderCreateService::class);
        $single = $service->quote([
            'advertiser_id' => $advertiser->id,
            'sites' => [[
                'site_id' => $siteA->id,
                'quantity' => 1,
                'content_source' => 'buyer',
                'content_url' => 'https://advertiser.example/a.docx',
                'links' => [['anchor' => 'Primary', 'url' => 'https://advertiser.example/a']],
            ]],
        ]);
        $quote = $service->quote([
            'advertiser_id' => $advertiser->id,
            'managed_external_reference' => 'Re: outreach thread #42',
            'sites' => [
                [
                    'site_id' => $siteA->id,
                    'quantity' => 3,
                    'content_source' => 'buyer',
                    'content_url' => 'https://advertiser.example/a.docx',
                    'links' => [
                        ['anchor' => 'Primary', 'url' => 'https://advertiser.example/a'],
                        ['anchor' => 'Secondary', 'url' => 'https://advertiser.example/b'],
                    ],
                ],
                [
                    'site_id' => $siteB->id,
                    'quantity' => 1,
                    'content_source' => 'slb',
                    'links' => [
                        ['anchor' => 'B anchor', 'url' => 'https://advertiser.example/b-landing'],
                    ],
                    'writing_brief_notes' => 'Tone: practical',
                ],
            ],
        ]);

        $this->assertTrue($quote['sufficient']);
        $this->assertSame(2, (int) $quote['placement_count']);
        $this->assertSame(4, (int) $quote['order_count']);
        $this->assertTrue((bool) $quote['lines'][0]['pack']);
        $this->assertGreaterThan(0, $quote['total']);
        $this->assertLessThan((float) $single['lines'][0]['unit_price'], (float) $quote['lines'][0]['unit_price']);
        $this->assertGreaterThan(0, (float) $quote['lines'][1]['writing_fee']);

        $key = $service->newIdempotencyKey();
        $this->actingAs($admin)
            ->post(route('admin.managed-orders.store'), [
                'advertiser_id' => $advertiser->id,
                'managed_external_reference' => 'Re: outreach thread #42',
                'sites' => [
                    [
                        'site_id' => $siteA->id,
                        'quantity' => 3,
                        'content_source' => 'buyer',
                        'content_url' => 'https://advertiser.example/a.docx',
                        'links' => [
                            ['anchor' => 'Primary', 'url' => 'https://advertiser.example/a'],
                            ['anchor' => 'Secondary', 'url' => 'https://advertiser.example/b'],
                        ],
                    ],
                    [
                        'site_id' => $siteB->id,
                        'quantity' => 1,
                        'content_source' => 'slb',
                        'links' => [
                            ['anchor' => 'B anchor', 'url' => 'https://advertiser.example/b-landing'],
                        ],
                        'writing_brief_notes' => 'Tone: practical',
                    ],
                ],
                'idempotency_key' => $key,
                'confirm_charge' => '1',
            ])
            ->assertRedirect();

        $orders = Order::query()->where('user_id', $advertiser->id)->orderBy('id')->get();
        $this->assertCount(4, $orders);
        $this->assertSame(1, $orders->pluck('reference_code')->unique()->count());
        $this->assertSame(1, WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('type', WalletTransaction::TYPE_PURCHASE)
            ->where('reference', $orders->first()->reference_code)
            ->count());

        $wallet->refresh();
        $this->assertEqualsWithDelta($before - (float) $quote['total'], (float) $wallet->balance, 0.05);

        $item = $orders->first()->items()->first();
        $this->assertNotNull($item);
        $this->assertTrue($item->isManagedBySlb());
        if (Schema::hasColumn('order_items', 'managed_external_reference')) {
            $this->assertSame('Re: outreach thread #42', $item->managed_external_reference);
        }

        if (Schema::hasTable('order_packs')) {
            $pack = OrderPack::query()->where('site_id', $siteA->id)->first();
            $this->assertNotNull($pack);
            $this->assertSame(3, (int) $pack->pack_size);
            $this->assertSame(3, $orders->where('order_pack_id', $pack->id)->count());
        }

        // Idempotent replay: same key does not debit again.
        $this->actingAs($admin)
            ->post(route('admin.managed-orders.store'), [
                'advertiser_id' => $advertiser->id,
                'managed_external_reference' => 'Re: outreach thread #42',
                'sites' => [
                    [
                        'site_id' => $siteA->id,
                        'quantity' => 3,
                        'content_source' => 'buyer',
                        'content_url' => 'https://advertiser.example/a.docx',
                        'links' => [
                            ['anchor' => 'Primary', 'url' => 'https://advertiser.example/a'],
                        ],
                    ],
                ],
                'idempotency_key' => $key,
                'confirm_charge' => '1',
            ])
            ->assertRedirect();

        $this->assertSame(4, Order::query()->where('user_id', $advertiser->id)->count());
        $this->assertSame(1, WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('type', WalletTransaction::TYPE_PURCHASE)
            ->count());
    }
}
