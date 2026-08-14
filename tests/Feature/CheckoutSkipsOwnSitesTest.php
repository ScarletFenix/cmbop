<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class CheckoutSkipsOwnSitesTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['content_moderation.enabled' => false]);
    }

    private function dualRoleUser(): User
    {
        $advertiser = Role::firstOrCreate(['name' => 'advertiser']);
        $publisher = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiser->id,
        ]);
        $user->roles()->attach([$advertiser->id, $publisher->id]);

        return $user->fresh();
    }

    private function makeSite(User $publisher, string $domain, float $price): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Site '.$domain,
            'site_url' => 'https://'.$domain,
            'domain' => $domain,
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
            'description' => str_repeat('Own-site checkout fixture. ', 3),
            'verified' => true,
            'active' => true,
        ]);
    }

    public function test_wallet_checkout_does_not_reserve_funds_for_the_buyers_own_site(): void
    {
        $buyer = $this->dualRoleUser();
        $otherPublisher = $this->dualRoleUser();
        Role::firstOrCreate(['name' => 'admin']);

        $ownSite = $this->makeSite($buyer, 'own-site.example', 80);
        $otherSite = $this->makeSite($otherPublisher, 'other-site.example', 40);

        Wallet::create([
            'user_id' => $buyer->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 200,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $ownSub = $this->createApprovedSubmission($buyer, $ownSite->id, 0, 'own anchor', 'https://example.com/own');
        $otherSub = $this->createApprovedSubmission($buyer, $otherSite->id, 0, 'other anchor', 'https://example.com/other');

        $this->actingAs($buyer)
            ->withSession([
                'cart' => [
                    ['id' => $ownSite->id, 'name' => $ownSite->site_name, 'quantity' => 1],
                    ['id' => $otherSite->id, 'name' => $otherSite->site_name, 'quantity' => 1],
                ],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'REF-OWN-SKIP',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $ownSite->id => [$ownSub->id],
                    $otherSite->id => [$otherSub->id],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(1, Order::query()->where('user_id', $buyer->id)->count());
        $order = Order::query()->where('user_id', $buyer->id)->first();
        $this->assertSame($otherSite->id, (int) $order->items()->value('site_id'));
        $this->assertNull(OrderItem::query()->where('site_id', $ownSite->id)->first());

        $wallet = Wallet::where('user_id', $buyer->id)
            ->where('role_id', Wallet::advertiserRoleId())
            ->first();
        $charged = round((float) $order->total_amount, 2);
        $this->assertGreaterThan(0, $charged);
        $this->assertEqualsWithDelta($charged, (float) $wallet->reserved_balance, 0.01);
        $this->assertEqualsWithDelta(200.0 - $charged, (float) $wallet->balance, 0.01);
        $this->assertLessThan(100.0, $charged);
    }

    public function test_wallet_checkout_rejects_a_cart_that_is_only_the_buyers_own_sites(): void
    {
        $buyer = $this->dualRoleUser();
        Role::firstOrCreate(['name' => 'admin']);
        $ownSite = $this->makeSite($buyer, 'only-own.example', 80);
        $this->fundAdvertiserWallet($buyer, 200);
        $sub = $this->createApprovedSubmission($buyer, $ownSite->id);

        $this->actingAs($buyer)
            ->withSession([
                'cart' => [
                    ['id' => $ownSite->id, 'name' => $ownSite->site_name, 'quantity' => 1],
                ],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'REF-OWN-ONLY',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $ownSite->id => [$sub->id],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(0, Order::query()->count());
        $wallet = Wallet::where('user_id', $buyer->id)
            ->where('role_id', Wallet::advertiserRoleId())
            ->first();
        $this->assertEqualsWithDelta(0.0, (float) $wallet->reserved_balance, 0.01);
    }
}
