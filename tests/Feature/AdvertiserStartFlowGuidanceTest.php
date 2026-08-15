<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class AdvertiserStartFlowGuidanceTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role->id);
        $user->active_role_id = $role->id;
        $user->save();

        return $user->fresh();
    }

    public function test_new_advertiser_dashboard_leads_with_catalog_and_guided_secondary(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->get(route('advertiser.dashboard'))
            ->assertOk()
            ->assertSee('Browse catalog', false)
            ->assertSee('Prefer a guided flow?', false)
            ->assertSee(route('advertiser.catalog'), false)
            ->assertSee(route('advertiser.wizard.start'), false)
            ->assertDontSee('Order an article', false)
            ->assertDontSee('Coming soon', false);
    }

    public function test_returning_advertiser_cta_points_to_catalog(): void
    {
        $advertiser = $this->advertiser();
        $this->makeCompletedOrder($advertiser);

        $this->actingAs($advertiser)
            ->get(route('advertiser.dashboard'))
            ->assertOk()
            ->assertSee('Browse catalog', false)
            ->assertSee(route('advertiser.catalog'), false)
            ->assertSee('Guided placement', false)
            ->assertSee(route('advertiser.wizard.start'), false)
            ->assertSee('Upload an article', false)
            ->assertSee('id="dashUploadLibraryAction"', false)
            ->assertDontSee('You have an approved article ready', false);
    }

    public function test_returning_advertiser_with_orderable_article_still_uses_catalog_cta(): void
    {
        $advertiser = $this->advertiser();
        $this->makeCompletedOrder($advertiser);
        $this->createApprovedSubmission($advertiser);

        $this->actingAs($advertiser)
            ->get(route('advertiser.dashboard'))
            ->assertOk()
            ->assertViewHas('hasOrderableArticle', true)
            ->assertSee('Browse catalog', false)
            ->assertSee('You have an approved article ready', false)
            ->assertSee('id="dashOrderableLibraryAction"', false)
            ->assertDontSee('id="dashUploadLibraryAction"', false)
            ->assertSee(route('advertiser.catalog'), false);
    }

    public function test_dashboard_detects_orderable_article_beyond_latest_twenty(): void
    {
        $advertiser = $this->advertiser();
        $this->makeCompletedOrder($advertiser);

        $olderOrderable = $this->createApprovedSubmission($advertiser);
        $olderOrderable->update(['title' => 'Older Orderable']);

        // Newer approved rows that are not orderable (missing market) would hide
        // the older one if the dashboard only scanned the latest 20.
        for ($i = 0; $i < 20; $i++) {
            $incomplete = $this->createApprovedSubmission($advertiser);
            $incomplete->update([
                'country' => '',
                'language' => '',
                'title' => 'Incomplete '.$i,
            ]);
        }

        $this->actingAs($advertiser)
            ->get(route('advertiser.dashboard'))
            ->assertOk()
            ->assertViewHas('hasOrderableArticle', true)
            ->assertSee('You have an approved article ready', false)
            ->assertSee('id="dashOrderableLibraryAction"', false);
    }

    public function test_dashboard_has_orderable_false_when_only_incomplete_approved(): void
    {
        $advertiser = $this->advertiser();
        $this->makeCompletedOrder($advertiser);

        $incomplete = $this->createApprovedSubmission($advertiser);
        $incomplete->update(['country' => '', 'language' => '']);

        $this->actingAs($advertiser)
            ->get(route('advertiser.dashboard'))
            ->assertOk()
            ->assertViewHas('hasOrderableArticle', false)
            ->assertSee('Upload an article', false)
            ->assertSee('id="dashUploadLibraryAction"', false)
            ->assertDontSee('id="dashOrderableLibraryAction"', false)
            ->assertDontSee('You have an approved article ready', false);
    }

    public function test_dashboard_has_orderable_false_when_only_incomplete_checkout_link(): void
    {
        $advertiser = $this->advertiser();
        $this->makeCompletedOrder($advertiser);

        $incomplete = $this->createApprovedSubmission($advertiser);
        $incomplete->update(['target_url' => null]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.dashboard'))
            ->assertOk()
            ->assertViewHas('hasOrderableArticle', false)
            ->assertSee('Upload an article', false)
            ->assertSee('id="dashUploadLibraryAction"', false)
            ->assertDontSee('id="dashOrderableLibraryAction"', false)
            ->assertDontSee('You have an approved article ready', false);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertViewHas('approvedArticleCount', 0);
    }

    public function test_dashboard_and_catalog_do_not_treat_replaceable_leftover_as_ready_to_order(): void
    {
        $advertiser = $this->advertiser();
        $this->makeCompletedOrder($advertiser);

        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        $publisher = User::factory()->create(['email_verified_at' => now()]);
        $publisher->roles()->attach($publisherRole->id);
        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Leftover Count Site',
            'site_url' => 'https://leftover-count.example',
            'domain' => 'leftover-count.example',
            'da' => 30,
            'dr' => 30,
            'traffic' => 500,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 40,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Test site',
            'verified' => true,
            'active' => true,
        ]);

        $submission = $this->createApprovedSubmission($advertiser);
        $leftover = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-'.uniqid(),
            'reference_code' => 'REF-'.uniqid(),
            'subtotal' => 46,
            'tax' => 0,
            'total_amount' => 46,
            'payment_method' => 'card',
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $leftover->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_submission_id' => $submission->id,
            'content_link' => 'https://example.com/article',
            'price' => 46,
        ]);
        $submission->update([
            'order_id' => $leftover->id,
            'order_item_id' => $item->id,
        ]);

        $this->assertTrue($submission->fresh()->load(['order', 'orderItems.order'])->isAvailableForPicker());
        $this->assertFalse($submission->fresh()->isReadyForCheckout());

        $this->actingAs($advertiser)
            ->get(route('advertiser.dashboard'))
            ->assertOk()
            ->assertViewHas('hasOrderableArticle', false)
            ->assertSee('Upload an article', false)
            ->assertSee('id="dashUploadLibraryAction"', false)
            ->assertDontSee('id="dashOrderableLibraryAction"', false)
            ->assertDontSee('You have an approved article ready', false);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertViewHas('approvedArticleCount', 0);
    }

    public function test_catalog_shows_missing_article_guidance_when_none_approved(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertSee('needs its own', false)
            ->assertSee('approved', false)
            ->assertSee(route('advertiser.content-library', ['upload' => 1]), false)
            ->assertDontSee('Order an article', false);
    }

    public function test_catalog_hides_missing_article_guidance_when_approved_exists(): void
    {
        $advertiser = $this->advertiser();
        $this->createApprovedSubmission($advertiser);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertViewHas('approvedArticleCount', 1)
            ->assertDontSee('Checkout needs an', false);
    }

    public function test_catalog_orderable_count_ignores_latest_n_cap(): void
    {
        $advertiser = $this->advertiser();

        // One older fully orderable article, then 50 newer incomplete approved rows.
        // Count must still be 1 (not 0 and not capped by the preview list limit).
        $older = $this->createApprovedSubmission($advertiser);
        $older->update(['title' => 'Older Orderable Catalog']);

        for ($i = 0; $i < 50; $i++) {
            $incomplete = $this->createApprovedSubmission($advertiser);
            $incomplete->update([
                'country' => '',
                'language' => '',
                'title' => 'Incomplete catalog '.$i,
            ]);
        }

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertViewHas('approvedArticleCount', 1)
            ->assertDontSee('Checkout needs an', false);
    }

    public function test_content_library_empty_state_explains_path(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->assertSee('upload', false)
            ->assertSee('.docx', false)
            ->assertSee('No articles yet', false)
            ->assertSee('Upload a .docx to get your first approved article', false)
            ->assertSee('Upload article', false)
            ->assertSee('Browse publishers', false)
            ->assertDontSee('Order an article', false);
    }

    private function makeCompletedOrder(User $advertiser): Order
    {
        return Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-'.uniqid(),
            'reference_code' => 'REF-'.uniqid(),
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'completed',
        ]);
    }
}
