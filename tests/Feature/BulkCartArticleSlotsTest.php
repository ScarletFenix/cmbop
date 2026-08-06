<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bulk deals (Buy 3–5) must land in the cart as N placements so the advertiser
 * can attach a separate Content Library article for each one to publish.
 */
class BulkCartArticleSlotsTest extends TestCase
{
    use RefreshDatabase;

    private User $advertiser;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $advertiserRole = Role::where('name', 'advertiser')->firstOrFail();
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();

        $this->advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $this->advertiser->roles()->attach($advertiserRole->id);

        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->attach($publisherRole->id);
    }

    private function makeBulkSite(): Site
    {
        return Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Bulk Pack Site',
            'site_url' => 'https://bulk-pack.example',
            'domain' => 'bulk-pack.example',
            'da' => 40,
            'dr' => 50,
            'traffic' => 20000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 100,
            'publication_time' => '7 days',
            'turnaround_time' => '48h',
            'link_type' => 'dofollow',
            'description' => 'Joined the bulk discount programme.',
            'verified' => true,
            'active' => 1,
            'bulk_discount_enabled' => 1,
            'bulk_discount_percent' => 10,
        ]);
    }

    public function test_bulk_add_starts_cart_line_at_three_article_slots(): void
    {
        $site = $this->makeBulkSite();

        $response = $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), [
                'id' => $site->id,
                'bulk' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $cart = session('cart');
        $this->assertIsArray($cart);
        $this->assertCount(1, $cart);
        $this->assertSame(3, (int) $cart[0]['quantity']);
        $this->assertSame([0, 0, 0], array_values($cart[0]['content_submission_ids'] ?? []));
        $this->assertStringContainsString('3 article placements', (string) $response->json('message'));
        $this->assertGreaterThan(0, (float) ($cart[0]['discount_percent'] ?? 0));
    }

    public function test_bulk_add_honours_selected_four_articles(): void
    {
        $site = $this->makeBulkSite();

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), [
                'id' => $site->id,
                'bulk' => 1,
                'quantity' => 4,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $cart = session('cart');
        $this->assertSame(4, (int) $cart[0]['quantity']);
        $this->assertCount(4, $cart[0]['content_submission_ids'] ?? []);
    }

    public function test_bulk_add_clamps_below_minimum_up_to_three(): void
    {
        $site = $this->makeBulkSite();

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), [
                'id' => $site->id,
                'bulk' => 1,
                'quantity' => 1,
            ])
            ->assertOk();

        $this->assertSame(3, (int) session('cart')[0]['quantity']);
    }

    public function test_regular_add_still_starts_at_one(): void
    {
        $site = $this->makeBulkSite();

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), [
                'id' => $site->id,
            ])
            ->assertOk();

        $this->assertSame(1, (int) session('cart')[0]['quantity']);
    }

    public function test_bulk_deal_cards_have_no_article_quantity_picker(): void
    {
        $this->makeBulkSite();

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        // Deal cards stay simple — quantity is fixed at 3 in the cart flow.
        $this->assertStringNotContainsString('bulk-deal-qty', $html);
        $this->assertStringNotContainsString('bulk-deal-card__articles', $html);
        $this->assertStringNotContainsString('4 articles', $html);
        $this->assertStringNotContainsString('5 articles', $html);
        $this->assertStringContainsString('data-bulk-hint="1"', $html);
        $this->assertStringContainsString('Add to cart', $html);

        $js = (string) file_get_contents(public_path('assets/js/catalog.js'));
        $this->assertStringContainsString('cartOptions.bulk = true', $js);
        $this->assertStringContainsString('cartOptions.quantity = 3', $js);
        $this->assertStringNotContainsString('bulk-deal-qty', $js);
    }
}
